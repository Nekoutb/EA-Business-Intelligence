<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Billing analytics — *issuance* lens of customer invoicing, complementing
 * Receivables (which lives in the *collection* lens).
 *
 * KPIs:
 *   B1. invoice_volume      Count + total of posted out_invoices in period
 *                           Source: account.move where move_type='out_invoice'
 *                                   AND state='posted' AND invoice_date in [from, to]
 *
 *   B2. time_to_invoice     Median latency from cargo "ready" moment
 *                           (= MAX scanned_date across cargo's equipment / vehicle
 *                           / wood lines) to invoice_date. Logistics-only;
 *                           returns pending if the tenant has no aw_cargo data.
 *
 *   B3. credit_note_rate    count(out_refund posted) ÷ count(out_invoice posted)
 *                           in the same period — a direct measure of billing
 *                           accuracy / customer-dispute volume.
 *
 * Period anchor: invoice_date (the actual billing event), in line with how
 * Receivables/Payables anchor for liquidity KPIs.
 *
 * Each KPI helper traps its own exceptions and returns pending=true on
 * failure so a single broken query never 500s the whole page.
 */
final class BillingService
{
    private OdooClient $odoo;

    /**
     * Per-page size when paginating invoices over Odoo (XML-RPC payload
     * guardrail). The page used to hard-cap the total fetch at 5000 —
     * which silently truncated on high-volume tenants and left the
     * user with the famous "189 of 5000 invoices scoped" footer. We
     * now paginate in slices of this size up to INVOICE_TOTAL_LIMIT,
     * so the full set is covered while still bounding the worst case.
     */
    private const INVOICE_PAGE_SIZE = 5000;

    /**
     * Safety ceiling on total invoices fetched per period. Tenants
     * above this size would spin Odoo for a long time on every cache
     * miss; at the ceiling we stop paginating and surface a "truncated
     * at N of M" banner in the diagnostic so the analyst sees it.
     * 50K invoices ≈ 10 round-trips ≈ a few seconds on the first hit,
     * then session-cached for 5 min.
     */
    private const INVOICE_TOTAL_LIMIT = 50000;

    /**
     * Legacy single-shot cap, still used by reconciliationDetails() and
     * computeReconciliationFigures(). Those flows are not on the
     * "median time-to-invoice across all invoices" code path and stay
     * on a single-page fetch deliberately (large reconciliation tables
     * are paginated by the view, not by the service). Kept as a named
     * alias of the page size for clarity.
     */
    private const INVOICE_FETCH_CAP = self::INVOICE_PAGE_SIZE;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);

        $pyFrom = $cyFrom->modify('-1 year');
        $pyTo   = $cyTo->modify('-1 year');

        // The shipment-anchored latency analysis is expensive (hits 4 models);
        // compute it once and share between the B2 KPI and the
        // "slowest billings" drill-down table at the bottom of the page.
        $latencyData = $this->shipmentLatencyData($cyFrom, $cyTo);

        $kpis = [
            'invoice_volume'    => $this->invoiceVolume($cyFrom, $cyTo, $pyFrom, $pyTo),
            'time_to_invoice'   => $this->timeToInvoiceFromData($latencyData),
            'credit_note_rate'  => $this->creditNoteRate($cyFrom, $cyTo, $pyFrom, $pyTo),
        ];

        // Trend granularity follows the user's mental model:
        //   YTD             → monthly line
        //   MTD / Month     → daily line
        // Same rule applies to both the time-to-invoice and the credit
        // note % charts, so they read symmetrically on every period.
        $type        = (string) ($period['type'] ?? 'ytd');
        $granularity = $type === 'ytd' ? 'month' : 'day';

        // Revenue reconciliation: Expected (scan-derived) → Billed (invoice
        // total) → Collected (billed × payment progress). Bucketed by
        // month (YTD) or week (MTD/Month) for the chart.
        $reconciliation = $this->revenueReconciliation($cyFrom, $cyTo, $period);

        return [
            'kpis'             => $kpis,
            'period_label'     => $periodLabel,
            // Top-N drill-down raised from 5 → 30. The view collapses
            // this list behind a <details> accordion (closed by default)
            // and surfaces an Excel export for the full 30.
            'slowest_billings'   => $this->slowestBillingsFromData($latencyData, 30),
            // New trend series — feed the three ECharts line charts that
            // sit directly beneath the three KPI cards. All three honour
            // the master period filter via the granularity computed above
            // (monthly on YTD, daily on MTD / Month).
            'invoice_volume_series'  => $this->invoiceVolumeSeries($cyFrom, $cyTo, $granularity),
            'time_to_invoice_series' => $this->timeToInvoiceSeries($latencyData, $cyFrom, $cyTo, $granularity),
            'credit_note_series'     => $this->creditNoteRateSeries($cyFrom, $cyTo, $granularity),
            'reconciliation'   => $reconciliation,
            'pending_overall'  => array_reduce(
                $kpis,
                fn ($acc, $k) => $acc && !empty($k['pending']),
                true
            ),
        ];
    }

    // ─── Revenue Reconciliation: Expected → Billed → Collected ───────────────
    //
    // Three figures, all on the same TAX-EXCLUSIVE (HT) basis so they can
    // be subtracted directly:
    //
    //   Expected  = OperationsService.scanRevenueBreakdown.total_revenue
    //               (sum across container/vehicle/bois categories of
    //                count × tariff). This is what the customer SHOULD
    //                have invoiced based on operational throughput.
    //
    //   Billed    = sum(account.move.amount_untaxed_signed)
    //               where move_type='out_invoice', state='posted',
    //               invoice_date in [from, to]. What was actually
    //               invoiced in the period.
    //
    //   Collected = sum per invoice of amount_untaxed_signed × payment_progress,
    //               where payment_progress = (amount_total - amount_residual) / amount_total.
    //               How much of the billed amount has actually been received,
    //               pro-rata across HT and taxes.
    //
    // Two diagnostic gaps:
    //   billing_gap     = Expected − Billed   (revenue we earned but didn't invoice)
    //   collection_gap  = Billed   − Collected (invoiced but not yet received)
    //
    // Trend buckets:
    //   YTD selected → monthly buckets (Jan, Feb, …, current month)
    //   MTD selected → weekly buckets within the current month
    //   Month selected → weekly buckets within the chosen month

    /**
     * Public wrapper — lets DashboardController (and future surfaces) call
     * the reconciliation logic without going through analyze() which
     * would also trigger KPI / latency work the dashboard doesn't need.
     *
     * @return array{
     *   headline: array{expected:float, billed:float, collected:float, billing_gap:float, collection_gap:float, billing_realisation_pct:?float, collection_realisation_pct:?float},
     *   buckets: array<int, array{label:string, from:string, to:string, expected:float, billed:float, collected:float}>,
     *   error: ?string
     * }
     */
    public function reconciliationOnly(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo] = ea_resolve_period($today, $period);
        return $this->revenueReconciliation($cyFrom, $cyTo, $period);
    }

    /**
     * Per-invoice transaction detail used by the "Reconciliation details"
     * panel and CSV export on /billing. Single fetch — same domain as the
     * headline reconciliation, but with all the fields needed for review:
     * customer, dates, HT/Tax/TTC, residual, payment progress, days
     * outstanding.
     *
     * @return array{rows: array<int, array<string, mixed>>, error: ?string}
     */
    public function reconciliationDetails(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$from, $to] = ea_resolve_period($today, $period);

        try {
            $invoices = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type', '=', 'out_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                [
                    'id', 'name', 'invoice_date', 'invoice_date_due',
                    'partner_id', 'currency_id',
                    'amount_untaxed_signed', 'amount_tax_signed',
                    'amount_total_signed', 'amount_residual_signed',
                    'payment_state', 'state', 'ref',
                ],
                ['limit' => self::INVOICE_FETCH_CAP, 'order' => 'invoice_date desc, id desc']
            );

            $today = new \DateTimeImmutable('today');
            $rows = [];
            foreach ($invoices as $inv) {
                $ht    = (float) ($inv['amount_untaxed_signed']  ?? 0);
                $tax   = (float) ($inv['amount_tax_signed']      ?? 0);
                $total = (float) ($inv['amount_total_signed']    ?? 0);
                $resid = (float) ($inv['amount_residual_signed'] ?? 0);
                $progress = $total > 0 ? max(0.0, min(1.0, ($total - $resid) / $total)) : 0.0;

                // Days outstanding = today - due_date (if past due) else 0.
                $daysOverdue = 0;
                $dueStr = (string) ($inv['invoice_date_due'] ?? '');
                if ($dueStr !== '') {
                    try {
                        $due = new \DateTimeImmutable($dueStr);
                        if ($today > $due && $resid > 0) {
                            $daysOverdue = (int) $today->diff($due)->days;
                        }
                    } catch (\Throwable $e) {
                        // bad date — leave at 0
                    }
                }

                $rows[] = [
                    'id'             => (int) $inv['id'],
                    'name'           => (string) ($inv['name'] ?? ''),
                    'ref'            => (string) ($inv['ref']  ?? ''),
                    'invoice_date'   => (string) ($inv['invoice_date']     ?? ''),
                    'due_date'       => $dueStr,
                    'partner'        => is_array($inv['partner_id'] ?? null) ? (string) $inv['partner_id'][1] : '',
                    'partner_id'     => is_array($inv['partner_id'] ?? null) ? (int) $inv['partner_id'][0]    : null,
                    'amount_untaxed' => $ht,
                    'amount_tax'     => $tax,
                    'amount_total'   => $total,
                    'amount_residual' => $resid,
                    'collected_ht'   => $ht * $progress,
                    'collection_pct' => round($progress * 100.0, 1),
                    'payment_state'  => (string) ($inv['payment_state'] ?? ''),
                    'days_overdue'   => $daysOverdue,
                ];
            }

            return ['rows' => $rows, 'error' => null];
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{
     *   headline: array{expected:float, billed:float, collected:float, billing_gap:float, collection_gap:float, billing_realisation_pct:?float, collection_realisation_pct:?float},
     *   buckets: array<int, array{label:string, from:string, to:string, expected:float, billed:float, collected:float}>,
     *   error: ?string
     * }
     */
    private function revenueReconciliation(
        \DateTimeImmutable $cyFrom,
        \DateTimeImmutable $cyTo,
        array $period
    ): array {
        $emptyHeadline = [
            'expected' => 0.0, 'billed' => 0.0, 'collected' => 0.0,
            'billing_gap' => 0.0, 'collection_gap' => 0.0,
            'billing_realisation_pct'     => null,
            'collection_realisation_pct'  => null,
        ];

        try {
            // 1) Headline figures for the full period
            $headline = $this->computeReconciliationFigures($cyFrom, $cyTo);

            // 2) Bucketed series for the trend chart
            $type = $period['type'] ?? 'ytd';
            $granularity = $type === 'ytd' ? 'month' : 'week';
            $buckets = $this->reconciliationBuckets($cyFrom, $cyTo, $granularity);

            return [
                'headline'    => $headline,
                'buckets'     => $buckets,
                'granularity' => $granularity,
                'error'       => null,
            ];
        } catch (\Throwable $e) {
            return [
                'headline'    => $emptyHeadline,
                'buckets'     => [],
                'granularity' => 'month',
                'error'       => $e->getMessage(),
            ];
        }
    }

    /**
     * Monthly reconciliation buckets in ~4 Odoo round-trips for the whole
     * window, replacing ~13 per month.
     *
     * Expected: OperationsService::scanRevenueByMonth (2 grouped calls).
     * Billed:   one read_group on posted customer invoices, month × payment
     *           state, summing amount_untaxed_signed.
     * Collected: HT × payment progress, exactly as the per-invoice path —
     *           fully-paid states weigh 1 (so their HT sum is their
     *           collected), and only the partial minority is fetched
     *           row-wise for its exact fraction. Not an approximation.
     *
     * @return array<int, array{label:string, from:string, to:string, expected:float, billed:float, collected:float}>
     */
    /**
     * Warehouse invoice facts usable for this tenant, or null to use Odoo.
     * Shares bi.warehouse_revenue: both read wh_fact_invoice, so a gate that
     * proved that table for one is evidence for the other.
     */
    private function whTenantId(): ?int
    {
        if (!config('bi.warehouse_revenue', false)) return null;
        try {
            $id = (int) (optional(tenant())->id ?? 0);
            if ($id <= 0) return null;
            return \Illuminate\Support\Facades\DB::table('wh_fact_invoice')
                ->where('tenant_id', $id)->limit(1)->exists() ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function reconciliationBucketsBatched(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $expectedByMonth = (new OperationsService($this->odoo))->scanRevenueByMonth($from, $to);

        $billedByMonth    = [];
        $collectedByMonth = [];

        // Billed, plus collected wherever a whole payment-state group settles
        // uniformly. Group sums carry total and residual too, so each group
        // is classified by arithmetic rather than by state name:
        //   residual 0        → fully settled  → collected += HT
        //   residual = total  → nothing paid   → contributes nothing
        //   anything else     → mixed          → fetched row-wise below
        // The dual-run gate caught the earlier assumption that only
        // paid/in_payment can contribute: reversed and credit-noted invoices
        // clear their residual too, and the original per-invoice formula
        // credits them. Classifying by residual reproduces it exactly.
        $mixed = [];
        $whT = $this->whTenantId();

        // P3 — the same grouping, read locally. wh_fact_invoice stores the
        // SIGNED amounts (its columns are fed from *_signed), so the residual
        // arithmetic below is identical without any sign juggling.
        $rows = null;
        if ($whT !== null) {
            try {
                $rows = [];
                foreach (\Illuminate\Support\Facades\DB::table('wh_fact_invoice')
                    ->where('tenant_id', $whT)
                    ->where('move_type', 'out_invoice')
                    ->where('state', 'posted')
                    ->whereNotNull('invoice_date')
                    ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                    ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') AS ym,
                                 COALESCE(payment_state, '') AS ps,
                                 SUM(amount_untaxed) AS ht, SUM(amount_total) AS tot,
                                 SUM(amount_residual) AS res")
                    ->groupBy(\Illuminate\Support\Facades\DB::raw("DATE_FORMAT(invoice_date, '%Y-%m')"), 'payment_state')
                    ->get() as $r) {
                    $rows[] = [
                        '__range'                => ['invoice_date' => ['from' => $r->ym . '-01']],
                        'payment_state'          => (string) $r->ps,
                        'amount_untaxed_signed'  => (float) $r->ht,
                        'amount_total_signed'    => (float) $r->tot,
                        'amount_residual_signed' => (float) $r->res,
                    ];
                }
                if ($rows === []) $rows = null;
            } catch (\Throwable $e) {
                $rows = null;
                \Illuminate\Support\Facades\Log::warning('billing warehouse group failed, using Odoo', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($rows === null) {
            $rows = $this->odoo->callModel('account.move', 'read_group', [
                'domain'  => [
                    ['move_type', '=', 'out_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                'fields'  => ['amount_untaxed_signed:sum', 'amount_total_signed:sum', 'amount_residual_signed:sum'],
                'groupby' => ['invoice_date:month', 'payment_state'],
                'lazy'    => false,
            ]);
        }
        foreach ((array) $rows as $r) {
            $m = $this->monthOfGroup($r, 'invoice_date');
            if ($m === null) continue;
            $ht    = (float) ($r['amount_untaxed_signed'] ?? 0);
            $total = (float) ($r['amount_total_signed'] ?? 0);
            $resid = (float) ($r['amount_residual_signed'] ?? 0);

            $billedByMonth[$m] = ($billedByMonth[$m] ?? 0.0) + $ht;

            if (abs($resid) < 0.005) {
                $collectedByMonth[$m] = ($collectedByMonth[$m] ?? 0.0) + $ht;
            } elseif (abs($resid - $total) >= 0.005) {
                $mixed[] = [$m, (string) ($r['payment_state'] ?? '')];
            }
        }

        // Mixed groups only — exact per-invoice progress, same formula as the
        // original loop. Typically a handful of invoices.
        $year = (int) $from->format('Y');
        foreach ($mixed as [$m, $state]) {
            $ms = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $me = $ms->modify('last day of this month');
            $lo = ($ms < $from ? $from : $ms)->format('Y-m-d');
            $hi = ($me > $to   ? $to   : $me)->format('Y-m-d');

            $invs = null;
            if ($whT !== null) {
                try {
                    $invs = \Illuminate\Support\Facades\DB::table('wh_fact_invoice')
                        ->where('tenant_id', $whT)
                        ->where('move_type', 'out_invoice')
                        ->where('state', 'posted')
                        ->where(\Illuminate\Support\Facades\DB::raw("COALESCE(payment_state, '')"), '=', $state)
                        ->whereBetween('invoice_date', [$lo, $hi])
                        ->limit(self::INVOICE_FETCH_CAP)
                        ->get(['amount_untaxed', 'amount_total', 'amount_residual'])
                        ->map(fn ($r) => [
                            'amount_untaxed_signed'  => (float) $r->amount_untaxed,
                            'amount_total_signed'    => (float) $r->amount_total,
                            'amount_residual_signed' => (float) $r->amount_residual,
                        ])->all();
                } catch (\Throwable $e) {
                    $invs = null;
                }
            }
            if ($invs === null) {
                $invs = $this->odoo->searchRead('account.move',
                    [
                        ['move_type', '=', 'out_invoice'], ['state', '=', 'posted'],
                        ['payment_state', '=', $state],
                        ['invoice_date', '>=', $lo],
                        ['invoice_date', '<=', $hi],
                    ],
                    ['amount_untaxed_signed', 'amount_total_signed', 'amount_residual_signed'],
                    ['limit' => self::INVOICE_FETCH_CAP]
                );
            }
            foreach ($invs as $inv) {
                $total = (float) ($inv['amount_total_signed'] ?? 0);
                if ($total <= 0) continue;
                $resid = (float) ($inv['amount_residual_signed'] ?? 0);
                $ht    = (float) ($inv['amount_untaxed_signed'] ?? 0);
                $collectedByMonth[$m] = ($collectedByMonth[$m] ?? 0.0)
                    + $ht * max(0.0, min(1.0, ($total - $resid) / $total));
            }
        }

        // Emit one bucket per calendar month in the window, clamped to it —
        // same shape and labels the chart already consumes.
        $buckets = [];
        $cursor  = new \DateTimeImmutable($from->format('Y-m-01'));
        while ($cursor <= $to) {
            $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);
            $m = (int) $cursor->format('n');
            $buckets[] = [
                'label'     => $cursor->format('M'),
                'from'      => ($cursor < $from ? $from : $cursor)->format('Y-m-d'),
                'to'        => ($monthEnd > $to ? $to : $monthEnd)->format('Y-m-d'),
                'expected'  => (float) ($expectedByMonth[$m]  ?? 0.0),
                'billed'    => (float) ($billedByMonth[$m]    ?? 0.0),
                'collected' => (float) ($collectedByMonth[$m] ?? 0.0),
            ];
            $cursor = $cursor->modify('first day of next month');
        }
        return $buckets;
    }

    /** Month number from a read_group row (__range preferred, label fallback). */
    private function monthOfGroup(array $row, string $field): ?int
    {
        $f = $row['__range'][$field]['from'] ?? $row['__range'][$field . ':month']['from'] ?? null;
        if (is_string($f) && strlen($f) >= 7) return (int) substr($f, 5, 2);
        $label = $row[$field . ':month'] ?? null;
        if (is_string($label) && ($ts = strtotime('1 ' . $label)) !== false) return (int) date('n', $ts);
        return null;
    }

    /**
     * Compute (expected, billed, collected) HT for [from, to].
     *
     * @return array{expected:float, billed:float, collected:float, billing_gap:float, collection_gap:float, billing_realisation_pct:?float, collection_realisation_pct:?float}
     */
    private function computeReconciliationFigures(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        // Expected — call OperationsService.scanRevenueBreakdown directly.
        $expected = 0.0;
        try {
            $opsService = new OperationsService($this->odoo);
            $opsBreakdown = $opsService->scanRevenueBreakdown($from, $to);
            $expected = (float) ($opsBreakdown['total_revenue'] ?? 0);
        } catch (\Throwable $e) {
            // Operations not reachable / no aw_cargo schema — treat as 0
        }

        // Billed + Collected — single fetch of invoices in window.
        // amount_untaxed_signed = HT, amount_total_signed = TTC,
        // amount_residual_signed = open balance (TTC). Pro-rata payment
        // progress to keep the basis consistent.
        $billed    = 0.0;
        $collected = 0.0;
        try {
            $invoices = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type', '=', 'out_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                ['id', 'amount_untaxed_signed', 'amount_total_signed', 'amount_residual_signed', 'payment_state'],
                ['limit' => self::INVOICE_FETCH_CAP, 'order' => 'invoice_date desc']
            );

            foreach ($invoices as $inv) {
                $ht    = (float) ($inv['amount_untaxed_signed'] ?? 0);
                $total = (float) ($inv['amount_total_signed']   ?? 0);
                $resid = (float) ($inv['amount_residual_signed'] ?? 0);

                $billed += $ht;

                if ($total > 0) {
                    $progress = max(0.0, min(1.0, ($total - $resid) / $total));
                    $collected += $ht * $progress;
                }
            }
        } catch (\Throwable $e) {
            // Account.move not reachable — leave billed/collected at 0
        }

        $billingGap    = $expected - $billed;
        $collectionGap = $billed   - $collected;

        return [
            'expected'                    => $expected,
            'billed'                      => $billed,
            'collected'                   => $collected,
            'billing_gap'                 => $billingGap,
            'collection_gap'              => $collectionGap,
            // Realisation %: how close we got to the upstream stage.
            // 100% = no gap; <100% = leakage; >100% = caught more than
            // operations measured (happens when prior-period scans get
            // billed in this period).
            'billing_realisation_pct'     => $expected > 0 ? 100.0 * $billed / $expected : null,
            'collection_realisation_pct'  => $billed   > 0 ? 100.0 * $collected / $billed : null,
        ];
    }

    /**
     * Generate per-bucket reconciliation data points for the chart.
     *
     * @return array<int, array{label:string, from:string, to:string, expected:float, billed:float, collected:float}>
     */
    private function reconciliationBuckets(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity
    ): array {
        $buckets = [];

        if ($granularity === 'month') {
            // Batched path (P1, Aug 2026): the per-month loop below fired
            // ~13 Odoo calls per month — ~117 for a YTD view, the bulk of a
            // 251-second page. Two grouped calls now cover scan revenue for
            // every month, and two more cover billed/collected. Identical
            // figures by construction; verified month-by-month by the
            // reconcheck diagnostic. Set bi.recon_batched=false to fall
            // back to the original loop.
            if (config('bi.recon_batched', true)) {
                try {
                    return $this->reconciliationBucketsBatched($from, $to);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('recon batched path failed, using per-month loop', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $cursor = new \DateTimeImmutable($from->format('Y-m-01'));
            while ($cursor <= $to) {
                $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);
                $bFrom = $cursor < $from ? $from : $cursor;
                $bTo   = $monthEnd > $to ? $to : $monthEnd;
                $figures = $this->computeReconciliationFigures($bFrom, $bTo);
                $buckets[] = [
                    'label'     => $cursor->format('M'),
                    'from'      => $bFrom->format('Y-m-d'),
                    'to'        => $bTo->format('Y-m-d'),
                    'expected'  => $figures['expected'],
                    'billed'    => $figures['billed'],
                    'collected' => $figures['collected'],
                ];
                $cursor = $cursor->modify('first day of next month');
            }
        } else { // week (W1..W5 anchored to start of month)
            $monthStart = new \DateTimeImmutable($from->format('Y-m-01'));
            $cursor = $monthStart;
            $weekIndex = 1;
            while ($cursor <= $to) {
                $weekEnd = $cursor->modify('+6 days')->setTime(23, 59, 59);
                $bFrom = $cursor < $from ? $from : $cursor;
                $bTo   = $weekEnd > $to ? $to : $weekEnd;
                if ($bFrom <= $bTo) {
                    $figures = $this->computeReconciliationFigures($bFrom, $bTo);
                    $buckets[] = [
                        'label'     => 'W' . $weekIndex,
                        'from'      => $bFrom->format('Y-m-d'),
                        'to'        => $bTo->format('Y-m-d'),
                        'expected'  => $figures['expected'],
                        'billed'    => $figures['billed'],
                        'collected' => $figures['collected'],
                    ];
                }
                $cursor = $cursor->modify('+7 days');
                $weekIndex++;
            }
        }

        return $buckets;
    }

    // ─── B1: Invoice Issuance Volume ─────────────────────────────────────────

    private function invoiceVolume(
        \DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo,
        \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo
    ): array {
        try {
            $cyCount = $this->countInvoices('out_invoice', $cyFrom, $cyTo);
            $pyCount = $this->countInvoices('out_invoice', $pyFrom, $pyTo);
            $cyTotal = $this->sumInvoiceAmount('out_invoice', $cyFrom, $cyTo);

            return [
                'value'   => number_format($cyCount),
                'sub'     => $cyTotal > 0
                    ? ea_money_short($cyTotal, 'XAF') . ' total'
                    : '— amounts —',
                'pending' => false,
                'vsPy'    => $pyCount > 0 ? 100.0 * ($cyCount - $pyCount) / $pyCount : null,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Invoice volume', $e);
        }
    }

    // ─── B2: Time-to-Invoice (shipment-anchored) ─────────────────────────────
    //
    // Path:
    //   account.move (out_invoice posted in period)
    //     ↑ aw_cargo.cargo.invoice_ids (one2many)
    //   aw_cargo.cargo
    //     ↓ equipment_ids / vehicule_ids / bois_ids
    //   max(scanned_date) → "ready to bill" timestamp
    //   latency = invoice.invoice_date − ready_timestamp
    //
    // Returns pending if the tenant has no aw_cargo schema (non-logistics
    // tenants will land here gracefully).

    private function shipmentLatencyData(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            // 1) Pull every invoice in the window via pagination — was a
            //    hard 5000-row cap before, now we loop offset/limit until
            //    the result set is exhausted (or we hit INVOICE_TOTAL_LIMIT
            //    as a safety stop). Tracks 'invoices_truncated' so the
            //    view can surface a banner if we did stop short.
            $domain = [
                ['move_type', '=', 'out_invoice'],
                ['state', '=', 'posted'],
                ['invoice_date', '>=', $from->format('Y-m-d')],
                ['invoice_date', '<=', $to->format('Y-m-d')],
            ];
            $invoices         = $this->fetchAllInvoices($domain);
            $invoicesTotalRaw = $this->odoo->searchCount('account.move', $domain);
            $truncated        = $invoicesTotalRaw > count($invoices);

            if (empty($invoices)) {
                return ['ok' => false, 'reason' => 'no_invoices_in_period'];
            }

            $invoiceById = [];
            foreach ($invoices as $inv) {
                $invoiceById[$inv['id']] = $inv;
            }
            $invoiceIds = array_keys($invoiceById);

            // 2) Cargos linked to those invoices — paginated for the
            //    same reason invoices are. Cargo count typically tracks
            //    invoice count 1:N so we reuse the same ceiling.
            $cargos = $this->fetchAllCargos($invoiceIds);

            if (empty($cargos)) {
                return ['ok' => false, 'reason' => 'no_cargo_links'];
            }

            $cargoById = [];
            foreach ($cargos as $c) {
                $cargoById[$c['id']] = $c;
            }
            $cargoIds = array_keys($cargoById);

            // 3) MAX(scanned_date) per cargo across equipment / vehicule / bois.
            $maxScanByCargo = [];
            foreach (['aw_cargo.equipment', 'aw_vehicule.vehicule', 'aw_bois.bois'] as $model) {
                try {
                    $rows = $this->odoo->callModel($model, 'read_group', [
                        'domain' => [
                            ['cargo_id', 'in', $cargoIds],
                            ['scanned', '=', true],
                        ],
                        'fields'  => ['scanned_date:max'],
                        'groupby' => ['cargo_id'],
                        'lazy'    => false,
                    ]);
                    foreach ($rows as $row) {
                        $cId = is_array($row['cargo_id'] ?? null)
                            ? $row['cargo_id'][0]
                            : ($row['cargo_id'] ?? null);
                        $d = $row['scanned_date'] ?? null;
                        if ($cId && $d) {
                            if (!isset($maxScanByCargo[$cId]) || $d > $maxScanByCargo[$cId]) {
                                $maxScanByCargo[$cId] = $d;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // model not present on this tenant (e.g. no bois) — skip silently
                }
            }

            // 4) Per-invoice latency (in days, signed).
            $rows = [];
            foreach ($cargos as $cargo) {
                $maxScan = $maxScanByCargo[$cargo['id']] ?? null;
                if (!$maxScan) continue;
                try {
                    $scanDt = new \DateTimeImmutable($maxScan);
                } catch (\Throwable $e) {
                    continue;
                }

                foreach ((array) ($cargo['invoice_ids'] ?? []) as $invId) {
                    if (!isset($invoiceById[$invId])) continue;
                    $inv = $invoiceById[$invId];
                    $invDateStr = (string) ($inv['invoice_date'] ?? '');
                    if ($invDateStr === '') continue;
                    try {
                        $invDt = new \DateTimeImmutable($invDateStr);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    $diff = $invDt->diff($scanDt);
                    $days = (int) $diff->days;
                    if ($invDt < $scanDt) $days = -$days;

                    $rows[] = [
                        'invoice_id'    => $invId,
                        'invoice_name'  => (string) ($inv['name'] ?? ''),
                        'invoice_date'  => $invDateStr,
                        'invoice_total' => (float) ($inv['amount_total_signed'] ?? 0),
                        'last_scan'     => $maxScan,
                        'latency_days'  => $days,
                        'cargo_id'      => $cargo['id'],
                        'cargo_name'    => (string) ($cargo['name'] ?? ''),
                        'transport_ref' => (string) ($cargo['transport_ref'] ?? ''),
                        'vessel_name'   => (string) ($cargo['vessel_name'] ?? ''),
                        'journey_ref'   => (string) ($cargo['journey_ref'] ?? ''),
                    ];
                }
            }

            return [
                'ok'                 => !empty($rows),
                'reason'             => empty($rows) ? 'no_scoped_pairs' : null,
                'rows'               => $rows,
                'invoices_total'     => count($invoiceIds),
                'invoices_total_raw' => $invoicesTotalRaw,
                'invoices_truncated' => $truncated,
                'invoices_scoped'    => count(array_unique(array_column($rows, 'invoice_id'))),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Paginated search_read on out_invoice up to INVOICE_TOTAL_LIMIT.
     * Loops offset += INVOICE_PAGE_SIZE until either the underlying
     * recordset is exhausted (a short page comes back) or we hit the
     * safety ceiling. Caller checks `count($invoices) < searchCount(...)`
     * to decide whether to surface the truncation banner.
     */
    private function fetchAllInvoices(array $domain): array
    {
        $all   = [];
        $offset = 0;
        while ($offset < self::INVOICE_TOTAL_LIMIT) {
            $limit = min(self::INVOICE_PAGE_SIZE, self::INVOICE_TOTAL_LIMIT - $offset);
            $page  = $this->odoo->searchRead(
                'account.move',
                $domain,
                ['id', 'invoice_date', 'name', 'amount_total_signed'],
                ['limit' => $limit, 'offset' => $offset, 'order' => 'invoice_date desc']
            );
            if (empty($page)) break;
            $all    = array_merge($all, $page);
            if (count($page) < $limit) break;   // last page reached
            $offset += $limit;
        }
        return $all;
    }

    /**
     * Paginated search_read on aw_cargo.cargo for a given set of
     * invoice_ids. Same loop pattern as fetchAllInvoices().
     */
    private function fetchAllCargos(array $invoiceIds): array
    {
        if (empty($invoiceIds)) return [];
        $all   = [];
        $offset = 0;
        while ($offset < self::INVOICE_TOTAL_LIMIT) {
            $limit = min(self::INVOICE_PAGE_SIZE, self::INVOICE_TOTAL_LIMIT - $offset);
            $page  = $this->odoo->searchRead(
                'aw_cargo.cargo',
                [['invoice_ids', 'in', $invoiceIds]],
                ['id', 'name', 'transport_ref', 'vessel_name', 'journey_ref', 'invoice_ids'],
                ['limit' => $limit, 'offset' => $offset]
            );
            if (empty($page)) break;
            $all    = array_merge($all, $page);
            if (count($page) < $limit) break;
            $offset += $limit;
        }
        return $all;
    }

    private function timeToInvoiceFromData(array $latency): array
    {
        if (empty($latency['ok'])) {
            $explainer = match ($latency['reason'] ?? null) {
                'no_invoices_in_period' => 'No customer invoices in period',
                'no_cargo_links'        => 'No invoice ↔ cargo links available',
                'no_scoped_pairs'       => 'No scanned cargos linked to invoices',
                default                 => 'Schema-pending',
            };
            return [
                'value'   => '— —',
                'sub'     => $explainer,
                'pending' => true,
                'error'   => $latency['error'] ?? null,
            ];
        }

        $days = array_column($latency['rows'], 'latency_days');
        sort($days);
        $median = $this->percentile($days, 50);

        // Distribution buckets — useful colour for the page even if the headline
        // is just a median.
        $buckets = [
            '<=0'  => 0,  // billed before/on completion
            '1-3'  => 0,
            '4-7'  => 0,
            '8-14' => 0,
            '15-30'=> 0,
            '30+'  => 0,
        ];
        foreach ($days as $d) {
            if ($d <= 0)        $buckets['<=0']++;
            elseif ($d <= 3)    $buckets['1-3']++;
            elseif ($d <= 7)    $buckets['4-7']++;
            elseif ($d <= 14)   $buckets['8-14']++;
            elseif ($d <= 30)   $buckets['15-30']++;
            else                $buckets['30+']++;
        }

        // For the "X of Y invoices scoped" footer prefer the *raw* invoice
        // count from search_count — it reflects every invoice in the
        // period regardless of whether we hit the pagination ceiling.
        // The fallback covers the rare case where searchCount itself
        // wasn't recorded (legacy callers).
        $totalForLabel = (int) ($latency['invoices_total_raw'] ?? $latency['invoices_total'] ?? 0);
        $truncated     = (bool) ($latency['invoices_truncated'] ?? false);

        return [
            'value'        => round($median, 1) . ' days',
            'sub'          => sprintf(
                '%d of %d invoices scoped%s',
                $latency['invoices_scoped'],
                $totalForLabel,
                $truncated ? ' · truncated at safety cap' : ''
            ),
            'pending'      => false,
            'distribution' => $buckets,
            'truncated'    => $truncated,
        ];
    }

    private function slowestBillingsFromData(array $latency, int $topN): array
    {
        if (empty($latency['ok']) || empty($latency['rows'])) return [];
        $rows = $latency['rows'];
        usort($rows, fn ($a, $b) => $b['latency_days'] <=> $a['latency_days']);
        return array_slice($rows, 0, $topN);
    }

    /**
     * Build a time-series of median time-to-invoice for the chart panel,
     * bucketed by day (MTD / Month) or month (YTD). Reuses the latency
     * rows already pulled by shipmentLatencyData() so we don't pay a
     * second Odoo round-trip — every row carries an invoice_date that
     * we group on.
     *
     * Output:
     *   [
     *     'granularity' => 'day' | 'month',
     *     'labels'      => ['2026-01', '2026-02', ...]  or  ['2026-05-01', '2026-05-02', ...],
     *     'median'      => [3.5, 4.2, ...]              days per bucket
     *     'count'       => [42, 58, ...]                invoices per bucket
     *   ]
     */
    public function timeToInvoiceSeries(array $latency, \DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity = 'month'): array
    {
        $empty = ['granularity' => $granularity, 'labels' => [], 'median' => [], 'count' => []];
        if (empty($latency['ok']) || empty($latency['rows'])) return $empty;

        $labels = $this->bucketRange($from, $to, $granularity);
        $byBucket = array_fill_keys($labels, []);

        // Date-format that drives bucketing — 'Y-m-d' for daily,
        // 'Y-m' for monthly. Matches bucketRange()'s label format.
        $dateFmt = $granularity === 'day' ? 'Y-m-d' : 'Y-m';

        foreach ($latency['rows'] as $row) {
            $invDate = (string) ($row['invoice_date'] ?? '');
            if ($invDate === '') continue;
            try {
                $bucket = (new \DateTimeImmutable($invDate))->format($dateFmt);
            } catch (\Throwable $e) {
                continue;
            }
            if (!isset($byBucket[$bucket])) continue;
            $byBucket[$bucket][] = (int) $row['latency_days'];
        }

        $median = [];
        $count  = [];
        foreach ($labels as $b) {
            $vals = $byBucket[$b] ?? [];
            sort($vals);
            $median[] = empty($vals) ? null : round($this->percentile($vals, 50), 1);
            $count[]  = count($vals);
        }

        return [
            'granularity' => $granularity,
            'labels'      => $labels,
            'median'      => $median,
            'count'       => $count,
        ];
    }

    /**
     * Build a time-series of credit-note % (= refunds ÷ invoices) for
     * the chart panel, bucketed by day (MTD / Month) or month (YTD).
     * Uses Odoo read_group on account.move grouped by date + move_type,
     * so this is a single round-trip regardless of period size.
     *
     * Output mirrors timeToInvoiceSeries():
     *   [
     *     'granularity' => 'day' | 'month',
     *     'labels'      => [...],
     *     'rate'        => [2.1, 3.0, ...]   percentage points per bucket
     *     'invoices'    => [120, 135, ...]
     *     'refunds'     => [3, 4, ...]
     *   ]
     */
    public function creditNoteRateSeries(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity = 'month'): array
    {
        $labels = $this->bucketRange($from, $to, $granularity);
        $empty  = [
            'granularity' => $granularity,
            'labels'      => $labels,
            'rate'        => array_fill(0, count($labels), null),
            'invoices'    => array_fill(0, count($labels), 0),
            'refunds'     => array_fill(0, count($labels), 0),
        ];

        try {
            // Odoo read_group keyword is 'invoice_date:month' or
            // 'invoice_date:day' — both supported by v15+.
            $groupKey = 'invoice_date:' . $granularity;
            $rows = $this->odoo->callModel('account.move', 'read_group', [
                'domain' => [
                    ['move_type', 'in', ['out_invoice', 'out_refund']],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                'fields'  => ['id:count'],
                'groupby' => [$groupKey, 'move_type'],
                'lazy'    => false,
            ]);
        } catch (\Throwable $e) {
            return $empty;
        }

        // Odoo returns labels like "May 2026" for month or "01 May 2026"
        // for day; we want them in Y-m or Y-m-d to align with our labels.
        // The __range subkey (when present) gives us iso-style 'from'
        // strings that are easier to parse.
        $invCounts    = array_fill_keys($labels, 0);
        $refundCounts = array_fill_keys($labels, 0);

        foreach ($rows as $r) {
            $rangeFrom = $r[$groupKey . '_range']['from']
                ?? ($r['__range'][$groupKey]['from'] ?? null);
            if (!$rangeFrom) {
                // Last-resort parse — read_group returns either a tuple
                // [iso_label, display_label] or a display string. Try
                // strtotime() and fall back if it can't make sense of it.
                $raw = $r[$groupKey] ?? null;
                if (is_array($raw) && isset($raw[0])) $rangeFrom = (string) $raw[0];
                elseif (is_string($raw))               $rangeFrom = $raw;
            }
            if (!$rangeFrom) continue;

            try {
                $bucket = (new \DateTimeImmutable($rangeFrom))
                    ->format($granularity === 'day' ? 'Y-m-d' : 'Y-m');
            } catch (\Throwable $e) {
                continue;
            }
            if (!isset($invCounts[$bucket])) continue;

            $mt    = $r['move_type'] ?? null;
            $mtKey = is_array($mt) ? ($mt[0] ?? null) : $mt;
            $n     = (int) ($r['id_count'] ?? $r['__count'] ?? 0);

            if ($mtKey === 'out_invoice') $invCounts[$bucket]    += $n;
            elseif ($mtKey === 'out_refund') $refundCounts[$bucket] += $n;
        }

        $rate     = [];
        $invOut   = [];
        $refOut   = [];
        foreach ($labels as $b) {
            $inv = $invCounts[$b];
            $ref = $refundCounts[$b];
            $rate[]   = $inv > 0 ? round(100.0 * $ref / $inv, 2) : null;
            $invOut[] = $inv;
            $refOut[] = $ref;
        }

        return [
            'granularity' => $granularity,
            'labels'      => $labels,
            'rate'        => $rate,
            'invoices'    => $invOut,
            'refunds'     => $refOut,
        ];
    }

    /**
     * Build a time-series of invoice issuance volume for the chart panel
     * — count of posted out_invoices per bucket. Bucketed by day (MTD /
     * Month) or month (YTD). Single Odoo round-trip via read_group.
     *
     * Used for the new trend chart directly beneath the "Invoice
     * Issuance" KPI card. Output mirrors creditNoteRateSeries() so
     * the view side stays a copy-paste of the same chart template:
     *   [
     *     'granularity' => 'day' | 'month',
     *     'labels'      => [...],
     *     'count'       => [120, 135, ...]   invoices per bucket
     *     'amount'      => [..., null, ...]  amount_total_signed sum
     *                                        (HT-signed) per bucket
     *   ]
     */
    public function invoiceVolumeSeries(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity = 'month'): array
    {
        $labels = $this->bucketRange($from, $to, $granularity);
        $empty  = [
            'granularity' => $granularity,
            'labels'      => $labels,
            'count'       => array_fill(0, count($labels), 0),
            'amount'      => array_fill(0, count($labels), 0.0),
        ];

        try {
            $groupKey = 'invoice_date:' . $granularity;
            $rows = $this->odoo->callModel('account.move', 'read_group', [
                'domain' => [
                    ['move_type', '=', 'out_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                'fields'  => ['id:count', 'amount_untaxed_signed:sum'],
                'groupby' => [$groupKey],
                'lazy'    => false,
            ]);
        } catch (\Throwable $e) {
            return $empty;
        }

        $countByBucket  = array_fill_keys($labels, 0);
        $amountByBucket = array_fill_keys($labels, 0.0);

        foreach ($rows as $r) {
            $rangeFrom = $r[$groupKey . '_range']['from']
                ?? ($r['__range'][$groupKey]['from'] ?? null);
            if (!$rangeFrom) {
                $raw = $r[$groupKey] ?? null;
                if (is_array($raw) && isset($raw[0])) $rangeFrom = (string) $raw[0];
                elseif (is_string($raw))               $rangeFrom = $raw;
            }
            if (!$rangeFrom) continue;

            try {
                $bucket = (new \DateTimeImmutable($rangeFrom))
                    ->format($granularity === 'day' ? 'Y-m-d' : 'Y-m');
            } catch (\Throwable $e) {
                continue;
            }
            if (!isset($countByBucket[$bucket])) continue;

            $countByBucket[$bucket]  += (int) ($r['id_count'] ?? $r['__count'] ?? 0);
            $amountByBucket[$bucket] += (float) ($r['amount_untaxed_signed'] ?? 0);
        }

        return [
            'granularity' => $granularity,
            'labels'      => $labels,
            'count'       => array_map(fn ($b) => $countByBucket[$b],  $labels),
            'amount'      => array_map(fn ($b) => $amountByBucket[$b], $labels),
        ];
    }

    /**
     * Generate an inclusive list of bucket labels between $from and
     * $to at the requested granularity. Used by both trend series
     * above so the x-axis is well-defined even when a bucket has zero
     * rows (avoids the "gappy chart" look).
     *
     *   'day'   → ['2026-05-01', '2026-05-02', …, '2026-05-12']
     *   'month' → ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05']
     */
    private function bucketRange(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity): array
    {
        $out = [];
        if ($granularity === 'day') {
            $cursor = $from->setTime(0, 0, 0);
            $end    = $to->setTime(0, 0, 0);
            while ($cursor <= $end) {
                $out[] = $cursor->format('Y-m-d');
                $cursor = $cursor->modify('+1 day');
            }
        } else {
            // Monthly — start at first-of-month for $from, walk one month
            // at a time until we pass $to.
            $cursor = $from->modify('first day of this month')->setTime(0, 0, 0);
            $end    = $to->modify('first day of this month')->setTime(0, 0, 0);
            while ($cursor <= $end) {
                $out[] = $cursor->format('Y-m');
                $cursor = $cursor->modify('+1 month');
            }
        }
        return $out;
    }

    // ─── B3: Credit Note Rate ────────────────────────────────────────────────

    private function creditNoteRate(
        \DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo,
        \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo
    ): array {
        try {
            $cyInv    = $this->countInvoices('out_invoice', $cyFrom, $cyTo);
            $cyRefund = $this->countInvoices('out_refund',  $cyFrom, $cyTo);
            $pyInv    = $this->countInvoices('out_invoice', $pyFrom, $pyTo);
            $pyRefund = $this->countInvoices('out_refund',  $pyFrom, $pyTo);

            if ($cyInv === 0) {
                return [
                    'value'   => '— —',
                    'sub'     => 'No invoices in period',
                    'pending' => true,
                ];
            }

            $cyRate = 100.0 * $cyRefund / max($cyInv, 1);
            $pyRate = $pyInv > 0 ? 100.0 * $pyRefund / $pyInv : null;

            return [
                'value'   => number_format($cyRate, 2) . '%',
                'sub'     => sprintf('%d credit notes / %d invoices', $cyRefund, $cyInv),
                'pending' => false,
                // Lower is better for credit-note rate. Invert sign so the
                // green/red colouring in the view tells the right story.
                'vsPy'    => $pyRate !== null ? -1.0 * ($cyRate - $pyRate) : null,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Credit note rate', $e);
        }
    }

    // ─── plumbing ────────────────────────────────────────────────────────────

    private function countInvoices(string $moveType, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->odoo->searchCount('account.move', [
            ['move_type', '=', $moveType],
            ['state', '=', 'posted'],
            ['invoice_date', '>=', $from->format('Y-m-d')],
            ['invoice_date', '<=', $to->format('Y-m-d')],
        ]);
    }

    private function sumInvoiceAmount(string $moveType, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        try {
            $rows = $this->odoo->callModel('account.move', 'read_group', [
                'domain' => [
                    ['move_type', '=', $moveType],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                'fields'  => ['amount_total_signed:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            return (float) ($rows[0]['amount_total_signed'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float) $sorted[0];
        $rank = ($p / 100) * ($n - 1);
        $lo   = (int) floor($rank);
        $hi   = (int) ceil($rank);
        if ($lo === $hi) return (float) $sorted[$lo];
        $frac = $rank - $lo;
        return (float) $sorted[$lo] + $frac * ((float) $sorted[$hi] - (float) $sorted[$lo]);
    }

    private function failed(string $sub, \Throwable $e): array
    {
        return [
            'value'   => '— —',
            'sub'     => $sub,
            'pending' => true,
            'error'   => $e->getMessage(),
        ];
    }
}
