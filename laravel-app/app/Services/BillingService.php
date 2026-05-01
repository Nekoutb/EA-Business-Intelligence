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

    /** Cap fetched invoices per period (XML-RPC payload guardrail). */
    private const INVOICE_FETCH_CAP = 5000;

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

        // Revenue reconciliation: Expected (scan-derived) → Billed (invoice
        // total) → Collected (billed × payment progress). Bucketed by
        // month (YTD) or week (MTD/Month) for the chart.
        $reconciliation = $this->revenueReconciliation($cyFrom, $cyTo, $period);

        return [
            'kpis'             => $kpis,
            'period_label'     => $periodLabel,
            'slowest_billings' => $this->slowestBillingsFromData($latencyData, 5),
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
            // 1) Pull invoices in window (id + date only — keep payload small).
            $invoices = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type', '=', 'out_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                ['id', 'invoice_date', 'name', 'amount_total_signed'],
                ['limit' => self::INVOICE_FETCH_CAP, 'order' => 'invoice_date desc']
            );

            if (empty($invoices)) {
                return ['ok' => false, 'reason' => 'no_invoices_in_period'];
            }

            $invoiceById = [];
            foreach ($invoices as $inv) {
                $invoiceById[$inv['id']] = $inv;
            }
            $invoiceIds = array_keys($invoiceById);

            // 2) Cargos linked to those invoices.
            $cargos = $this->odoo->searchRead(
                'aw_cargo.cargo',
                [['invoice_ids', 'in', $invoiceIds]],
                ['id', 'name', 'transport_ref', 'vessel_name', 'journey_ref', 'invoice_ids'],
                ['limit' => self::INVOICE_FETCH_CAP]
            );

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
                'ok'             => !empty($rows),
                'reason'         => empty($rows) ? 'no_scoped_pairs' : null,
                'rows'           => $rows,
                'invoices_total' => count($invoiceIds),
                'invoices_scoped' => count(array_unique(array_column($rows, 'invoice_id'))),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
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

        return [
            'value'        => round($median, 1) . ' days',
            'sub'          => sprintf(
                '%d of %d invoices scoped',
                $latency['invoices_scoped'],
                $latency['invoices_total']
            ),
            'pending'      => false,
            'distribution' => $buckets,
        ];
    }

    private function slowestBillingsFromData(array $latency, int $topN): array
    {
        if (empty($latency['ok']) || empty($latency['rows'])) return [];
        $rows = $latency['rows'];
        usort($rows, fn ($a, $b) => $b['latency_days'] <=> $a['latency_days']);
        return array_slice($rows, 0, $topN);
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
