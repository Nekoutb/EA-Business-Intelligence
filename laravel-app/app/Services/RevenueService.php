<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Computes revenue KPIs from Odoo.
 *
 *   - Total revenue, current year (YTD) vs prior year (same window)
 *   - Revenue per day    = total revenue / number of days in window
 *   - Revenue per outfit = total revenue / number of outfits (order/invoice lines)
 *   - Daily trend series for CY and PY (for the chart)
 *
 * "Revenue source" is configurable:
 *   - invoice : account.move (out_invoice - out_refund), posted
 *   - sale    : sale.order, state in ('sale','done')
 *   - pos     : pos.order, state in ('paid','invoiced','done')
 */
final class RevenueService
{
    private OdooClient $odoo;
    private string $source;

    private float $budgetMonthlyRevenue;
    private float $budgetMonthlyOutfits;
    private float $budgetMonthlyCogs;
    private float $budgetMonthlyDiscounts;
    private float $budgetMonthlyCustomers;

    public function __construct(OdooClient $odoo, string $source = 'invoice')
    {
        $this->odoo = $odoo;
        $this->source = strtolower($source);

        $this->budgetMonthlyRevenue   = (float) config_env('BUDGET_MONTHLY_REVENUE', '8187000');
        $this->budgetMonthlyOutfits   = (float) config_env('BUDGET_MONTHLY_OUTFITS', '125');
        $this->budgetMonthlyCogs      = (float) config_env('BUDGET_MONTHLY_COGS', '2134289');
        $this->budgetMonthlyDiscounts = (float) config_env('BUDGET_MONTHLY_DISCOUNTS', '0');
        $this->budgetMonthlyCustomers = (float) config_env('BUDGET_MONTHLY_CUSTOMERS', '0');
    }

    /**
     * Returns the 7 headline revenue KPIs (CY vs PY, same-length window):
     *   1. total            - Total revenue YTD
     *   2. yoy_growth_pct   - YoY growth rate (%)
     *   3. per_day          - Revenue per day (run-rate)
     *   4. outfits          - Outfits sold (volume)
     *   5. per_outfit       - Average revenue per outfit (AOV)
     *   6. customers        - Unique customers (reach)
     *   7. per_customer     - Revenue per customer
     */
    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);

        $pyFrom = $cyFrom->modify('-1 year');
        $pyTo   = $cyTo->modify('-1 year');

        $days = (int) $cyFrom->diff($cyTo)->days + 1;

        $cy = $this->fetchWindow($cyFrom, $cyTo);
        $py = $this->fetchWindow($pyFrom, $pyTo);

        $cyWorkingDays = $this->workingDays($cyFrom, $cyTo);
        $pyWorkingDays = $this->workingDays($pyFrom, $pyTo);

        $cyCostBase = $this->fetchCogsAndSalesDiscounts($cyFrom, $cyTo);
        $pyCostBase = $this->fetchCogsAndSalesDiscounts($pyFrom, $pyTo);

        $current = [
            'total'               => $cy['total'],
            'outfits'             => $cy['outfits'],
            'customers'           => $cy['customers'],
            'per_day'             => $days > 0 ? $cy['total'] / $days : 0.0,
            'per_outfit'          => $cy['outfits']   > 0 ? $cy['total'] / $cy['outfits']   : 0.0,
            'per_customer'        => $cy['customers'] > 0 ? $cy['total'] / $cy['customers'] : 0.0,
            'series'              => $cy['series'],
            'total_discount'      => $cy['total_discount'],
            'discounted_outfits'  => $cy['discounted_outfits'],
            'avg_discount_outfit' => $cy['discounted_outfits'] > 0
                ? $cy['total_discount'] / $cy['discounted_outfits'] : 0.0,
            'working_days'        => $cyWorkingDays,
            'rpd'                 => $cyWorkingDays > 0 ? $cy['total'] / $cyWorkingDays : 0.0,
            'rpo'                 => $cy['outfits'] > 0 ? $cy['total'] / $cy['outfits'] : 0.0,
            'opd'                 => $cyWorkingDays > 0 ? $cy['outfits'] / $cyWorkingDays : 0.0,
            'dp_ot'               => $cy['outfits'] > 0 ? $cy['total_discount'] / $cy['outfits'] : 0.0,
            'dp_c'                => $cy['customers'] > 0 ? $cy['total_discount'] / $cy['customers'] : 0.0,
            'cpo'                 => $cy['outfits'] > 0 ? $cyCostBase / $cy['outfits'] : 0.0,
            'cost_base'           => $cyCostBase,
        ];
        $previous = [
            'total'               => $py['total'],
            'outfits'             => $py['outfits'],
            'customers'           => $py['customers'],
            'per_day'             => $days > 0 ? $py['total'] / $days : 0.0,
            'per_outfit'          => $py['outfits']   > 0 ? $py['total'] / $py['outfits']   : 0.0,
            'per_customer'        => $py['customers'] > 0 ? $py['total'] / $py['customers'] : 0.0,
            'series'              => $py['series'],
            'total_discount'      => $py['total_discount'],
            'discounted_outfits'  => $py['discounted_outfits'],
            'avg_discount_outfit' => $py['discounted_outfits'] > 0
                ? $py['total_discount'] / $py['discounted_outfits'] : 0.0,
            'working_days'        => $pyWorkingDays,
            'rpd'                 => $pyWorkingDays > 0 ? $py['total'] / $pyWorkingDays : 0.0,
            'rpo'                 => $py['outfits'] > 0 ? $py['total'] / $py['outfits'] : 0.0,
            'opd'                 => $pyWorkingDays > 0 ? $py['outfits'] / $pyWorkingDays : 0.0,
            'dp_ot'               => $py['outfits'] > 0 ? $py['total_discount'] / $py['outfits'] : 0.0,
            'dp_c'                => $py['customers'] > 0 ? $py['total_discount'] / $py['customers'] : 0.0,
            'cpo'                 => $py['outfits'] > 0 ? $pyCostBase / $py['outfits'] : 0.0,
            'cost_base'           => $pyCostBase,
        ];

        return [
            'source'   => $this->source,
            'currency' => $this->detectCurrency(),
            'period'   => [
                'from'  => $cyFrom->format('Y-m-d'),
                'to'    => $cyTo->format('Y-m-d'),
                'days'  => $days,
                'label' => $periodLabel,
            ],
            'current'        => $current,
            'previous'       => $previous,
            'yoy_growth_pct' => $this->pct($current['total'], $previous['total']),
            'delta'    => [
                'total_pct'        => $this->pct($current['total'],        $previous['total']),
                'per_day_pct'      => $this->pct($current['per_day'],      $previous['per_day']),
                'per_outfit_pct'   => $this->pct($current['per_outfit'],   $previous['per_outfit']),
                'outfits_pct'      => $this->pct((float) $current['outfits'],   (float) $previous['outfits']),
                'customers_pct'    => $this->pct((float) $current['customers'], (float) $previous['customers']),
                'per_customer_pct' => $this->pct($current['per_customer'], $previous['per_customer']),
            ],
            // Raw per-partner revenue for the YTD window; kept here so the deep-dive
            // page can build a top-customers table without another Odoo roundtrip.
            'partner_revenue_cy' => $cy['partner_revenue'],
            'partner_names_cy'   => $cy['partner_names'],
            'partner_orders_cy'  => $cy['partner_orders'],
            'partner_months_cy'  => $cy['partner_months'],
            'partner_revenue_py' => $py['partner_revenue'],
            'partner_names_py'   => $py['partner_names'],
            'partner_orders_py'  => $py['partner_orders'],
            'partner_months_py'  => $py['partner_months'],
            'doc_ids_cy'         => $cy['doc_ids'],
            'line_scope_cy'      => $cy['line_scope'] ?? [],
            'budget'             => $this->buildBudgetPackage($cyFrom, $cyTo, $cy['series']),
        ];
    }

    /**
     * @return array{total:float,outfits:int,customers:int,series:array<string,float>,
     *   partner_revenue:array<int,float>,partner_names:array<int,string>,doc_ids:int[]}
     */
    private function fetchWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$model, $domain, $dateField, $amountField, $lineModel, $lineParentField] = $this->modelConfig($from, $to);

        // Scope for line queries: the parent domain with each condition
        // prefixed by the parent link, e.g. move_id.invoice_date >= X. Lets
        // downstream product sections target lines directly instead of
        // needing a list of every document id.
        $lineScope = [];
        foreach ($domain as $cond) {
            $lineScope[] = (is_array($cond) && count($cond) === 3)
                ? [$lineParentField . '.' . $cond[0], $cond[1], $cond[2]]
                : $cond;
        }

        // Batched path (P1, Aug 2026): totals, the daily series and the
        // per-customer aggregates are all group-bys. Walking every document
        // for two years cost ~32 round-trips per analyse and was the largest
        // remaining slice of the dashboard. Identical arithmetic — Odoo sums
        // the same field over the same domain.
        $records = null;

        // P3 — the same three aggregates, read locally.
        //
        // Only for the invoice source: the warehouse holds account.move, and
        // a tenant configured for sale.order or pos.order has no local table
        // to answer from, so those keep going to Odoo.
        if ($model === 'account.move' && ($whT = $this->whTenantId()) !== null) {
            try {
                $base = fn () => \Illuminate\Support\Facades\DB::table('wh_fact_invoice')
                    ->where('wh_fact_invoice.tenant_id', $whT)
                    ->whereIn('wh_fact_invoice.move_type', ['out_invoice', 'out_refund'])
                    ->where('wh_fact_invoice.state', 'posted')
                    ->whereNotNull('wh_fact_invoice.invoice_date')
                    ->whereBetween('wh_fact_invoice.invoice_date',
                        [$from->format('Y-m-d'), $to->format('Y-m-d')]);

                $total = 0.0; $series = []; $orderIds = [];
                $partnerRevenue = []; $partnerNames = []; $partnerOrders = []; $partnerMonths = [];

                foreach ($base()
                    ->selectRaw('wh_fact_invoice.invoice_date AS d, SUM(wh_fact_invoice.amount_untaxed) AS amt')
                    ->groupBy('wh_fact_invoice.invoice_date')->get() as $r) {
                    $amt = (float) $r->amt;
                    $total += $amt;
                    $series[(string) $r->d] = ($series[(string) $r->d] ?? 0.0) + $amt;
                }

                foreach ($base()
                    ->leftJoin('wh_dim_partner as p', function ($j) {
                        $j->on('p.tenant_id', '=', 'wh_fact_invoice.tenant_id')
                          ->on('p.odoo_id', '=', 'wh_fact_invoice.partner_odoo_id');
                    })
                    ->whereNotNull('wh_fact_invoice.partner_odoo_id')
                    ->selectRaw('wh_fact_invoice.partner_odoo_id AS pid, MAX(p.name) AS pname,
                                 SUM(wh_fact_invoice.amount_untaxed) AS amt, COUNT(*) AS n')
                    ->groupBy('wh_fact_invoice.partner_odoo_id')->get() as $r) {
                    $pid = (int) $r->pid;
                    $partnerRevenue[$pid] = (float) $r->amt;
                    $partnerNames[$pid]   = (string) ($r->pname ?? '');
                    $partnerOrders[$pid]  = (int) $r->n;
                }

                foreach ($base()
                    ->whereNotNull('wh_fact_invoice.partner_odoo_id')
                    ->selectRaw("wh_fact_invoice.partner_odoo_id AS pid,
                                 DATE_FORMAT(wh_fact_invoice.invoice_date, '%Y-%m') AS ym")
                    ->groupBy('wh_fact_invoice.partner_odoo_id',
                        \Illuminate\Support\Facades\DB::raw("DATE_FORMAT(wh_fact_invoice.invoice_date, '%Y-%m')"))
                    ->get() as $r) {
                    $partnerMonths[(int) $r->pid][(string) $r->ym] = true;
                }

                // Line metrics (outfits = product-line count, discounts)
                // from the warehouse too, when this tenant's lines are
                // synced — for direct-DB tenants there is no live Odoo to
                // fall back to, and one throw below used to blank the
                // whole page. Odoo tenants without line rows keep the
                // live path unchanged.
                if (\Illuminate\Support\Facades\DB::table('wh_fact_invoice_line')
                        ->where('tenant_id', $whT)->limit(1)->exists()) {
                    $la = \Illuminate\Support\Facades\DB::table('wh_fact_invoice_line')
                        ->where('tenant_id', $whT)
                        ->whereIn('move_type', ['out_invoice', 'out_refund'])
                        ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                        ->selectRaw('COUNT(*) n,
                                     SUM(CASE WHEN discount > 0 THEN price_unit * quantity * discount / 100 ELSE 0 END) disc_amt,
                                     SUM(CASE WHEN discount > 0 THEN 1 ELSE 0 END) disc_n')
                        ->first();
                    $whLineMetrics = [
                        'outfits'            => (int) ($la->n ?? 0),
                        'total_discount'     => (float) ($la->disc_amt ?? 0),
                        'discounted_outfits' => (int) ($la->disc_n ?? 0),
                    ];
                }
            } catch (\Throwable $e) {
                // Drop every partial aggregate — inheriting half a result is
                // how a fallback path silently reports wrong totals.
                unset($total, $series, $orderIds, $partnerRevenue, $partnerNames, $partnerOrders, $partnerMonths, $whLineMetrics);
                \Illuminate\Support\Facades\Log::warning('revenue warehouse aggregate failed, using Odoo', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!isset($partnerRevenue) && config('bi.revenue_batched', true)) {
            try {
                $total = 0.0; $series = []; $orderIds = [];
                $partnerRevenue = []; $partnerNames = []; $partnerOrders = []; $partnerMonths = [];

                foreach ((array) $this->odoo->callModel($model, 'read_group', [
                    'domain' => $domain, 'fields' => [$amountField . ':sum'],
                    'groupby' => [$dateField . ':day'], 'lazy' => false,
                ]) as $g) {
                    $day = $this->dayOfGroup($g, $dateField);
                    $amt = (float) ($g[$amountField] ?? 0);
                    $total += $amt;
                    if ($day !== null) $series[$day] = ($series[$day] ?? 0.0) + $amt;
                }

                foreach ((array) $this->odoo->callModel($model, 'read_group', [
                    'domain' => $domain, 'fields' => [$amountField . ':sum'],
                    'groupby' => ['partner_id'], 'lazy' => false,
                ]) as $g) {
                    $pv = $g['partner_id'] ?? null;
                    if (!is_array($pv)) continue;
                    $pid = (int) $pv[0];
                    $partnerRevenue[$pid] = ($partnerRevenue[$pid] ?? 0.0) + (float) ($g[$amountField] ?? 0);
                    $partnerNames[$pid]   = $partnerNames[$pid] ?? (string) ($pv[1] ?? '');
                    $partnerOrders[$pid]  = ($partnerOrders[$pid] ?? 0) + (int) ($g['__count'] ?? 0);
                }

                foreach ((array) $this->odoo->callModel($model, 'read_group', [
                    'domain' => $domain, 'fields' => [],
                    'groupby' => ['partner_id', $dateField . ':month'], 'lazy' => false,
                ]) as $g) {
                    $pv = $g['partner_id'] ?? null;
                    if (!is_array($pv)) continue;
                    $day = $this->dayOfGroup($g, $dateField);
                    if ($day !== null) $partnerMonths[(int) $pv[0]][substr($day, 0, 7)] = true;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('revenue batched aggregate failed, paginating', [
                    'error' => $e->getMessage(),
                ]);
                // Discard any partial aggregate so the row path below runs
                // from a clean slate rather than inheriting half a result.
                unset($total, $series, $orderIds, $partnerRevenue, $partnerNames, $partnerOrders, $partnerMonths);
                $records = null;
            }
        }

        // Row fetch — original path, used when the batched aggregate is
        // disabled or failed.
        if (!isset($partnerRevenue)) {
            $records = [];
            $offset = 0;
            $limit  = 1000;
            do {
                $batch = $this->odoo->searchRead($model, $domain, [$dateField, $amountField, 'partner_id', 'id'], [
                    'offset' => $offset,
                    'limit'  => $limit,
                    'order'  => $dateField . ' asc',
                ]);
                foreach ($batch as $r) {
                    $records[] = $r;
                }
                $offset += $limit;
            } while (count($batch) === $limit);
        }

        if ($records !== null) {
        $total   = 0.0;
        $series  = [];
        $orderIds = [];
        $partnerRevenue = [];   // partnerId => revenue
        $partnerNames   = [];   // partnerId => display name
        $partnerOrders  = [];   // partnerId => order count
        $partnerMonths  = [];   // partnerId => ['YYYY-MM' => true]
        foreach ($records as $r) {
            // Invoice source uses amount_untaxed_signed (HT, pre-VAT, signed
            // in company currency) so revenue here matches the OHADA P&L
            // definition and reconciles with class 70 GL movements. Refunds
            // come through as negative automatically.
            // History note: was amount_total_signed (TTC) until 2026-05-11
            // — moved to HT so the Dashboard headline figure agrees with
            // budgets (always entered HT) and with /product-revenue-tsd.
            $amt = (float) ($r[$amountField] ?? 0);
            $total += $amt;
            $day = substr((string) ($r[$dateField] ?? ''), 0, 10);
            if ($day !== '') {
                $series[$day] = ($series[$day] ?? 0.0) + $amt;
            }
            if (isset($r['id'])) {
                $orderIds[] = (int) $r['id'];
            }
            // Odoo returns many2one as [id, display_name] or false if unset.
            if (isset($r['partner_id']) && is_array($r['partner_id'])) {
                $pid = (int) $r['partner_id'][0];
                $pname = (string) ($r['partner_id'][1] ?? '');
                $partnerRevenue[$pid] = ($partnerRevenue[$pid] ?? 0.0) + $amt;
                if (!isset($partnerNames[$pid])) {
                    $partnerNames[$pid] = $pname;
                }
                $partnerOrders[$pid] = ($partnerOrders[$pid] ?? 0) + 1;
                if ($day !== '') {
                    $monthKey = substr($day, 0, 7);
                    if ($monthKey !== '') {
                        $partnerMonths[$pid][$monthKey] = true;
                    }
                }
            }
        }
        } // /row-path aggregation
        $customers = count($partnerRevenue);

        // Count outfits and compute discount metrics from product lines.
        // NOTE: Odoo 16+ changed account.move.line.display_type so product lines are
        // 'product' (not False as in v15-). We therefore EXCLUDE non-product display
        // types, which is forward- and backward-compatible across Odoo 13 -> 18+.
        $outfits = 0;
        $totalDiscount = 0.0;
        $discountedOutfits = 0;
        if (isset($whLineMetrics)) {
            $outfits           = $whLineMetrics['outfits'];
            $totalDiscount     = $whLineMetrics['total_discount'];
            $discountedOutfits = $whLineMetrics['discounted_outfits'];
        } elseif ($lineModel !== null) {
            $lineDomain = $orderIds !== []
                ? [[$lineParentField, 'in', $orderIds]]
                : $lineScope;
            if ($lineModel === 'account.move.line') {
                $lineDomain[] = ['display_type', 'not in',
                    ['line_section', 'line_note', 'payment_term', 'tax', 'cogs', 'rounding']];
            } elseif ($lineModel === 'sale.order.line') {
                $lineDomain[] = ['display_type', 'not in', ['line_section', 'line_note']];
            }

            // Determine quantity field name
            $qtyField = ($lineModel === 'sale.order.line') ? 'product_uom_qty' : 'quantity';

            // Batched path (P1, Aug 2026): the loop below downloads EVERY
            // product line — tens of thousands of rows and ~30-100 round
            // trips per window, twice (current + prior year). Two facts make
            // that unnecessary: `outfits` is just a row count, and discount
            // metrics only involve lines that actually carry a discount.
            // So: count via read_group, then fetch only discounted lines.
            // Identical figures — same domain, same per-line arithmetic.
            if (config('bi.revenue_batched', true)) {
                try {
                    $outfits = 0;
                    // Chunk the id list so a single domain payload stays sane.
                    $chunks = $orderIds !== [] ? array_chunk($orderIds, 5000) : [null];
                    foreach ($chunks as $idChunk) {
                        $chunkDomain = $lineDomain;
                        if ($idChunk !== null) {
                            $chunkDomain[0] = [$lineParentField, 'in', $idChunk];
                        }
                        $g = $this->odoo->callModel($lineModel, 'read_group', [
                            'domain' => $chunkDomain, 'fields' => [], 'groupby' => [], 'lazy' => false,
                        ]);
                        $outfits += (int) ($g[0]['__count'] ?? 0);

                        // Only discounted lines can move the discount totals.
                        $discRows = $this->odoo->searchRead(
                            $lineModel,
                            array_merge($chunkDomain, [['discount', '>', 0]]),
                            ['discount', 'price_unit', $qtyField],
                            ['limit' => 20000]
                        );
                        foreach ($discRows as $line) {
                            $disc = (float) ($line['discount'] ?? 0);
                            if ($disc <= 0) continue;
                            $totalDiscount += ((float) ($line['price_unit'] ?? 0))
                                * ((float) ($line[$qtyField] ?? 0)) * ($disc / 100.0);
                            $discountedOutfits++;
                        }
                    }
                    // Skip the row-by-row pass below.
                    $allLines = null;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('revenue batched line path failed, using row fetch', [
                        'error' => $e->getMessage(),
                    ]);
                    $allLines = [];
                }
            } else {
                $allLines = [];
            }

            // Fetch lines with discount data to compute discount metrics.
            // Guarded: a failure here (e.g. no live source on a direct-DB
            // tenant) degrades these metrics to zero instead of taking the
            // whole page down.
            if ($allLines !== null) {
            try {
            $lineOffset = 0;
            $lineBatch  = 1000;
            $allLines   = [];
            do {
                $lBatch = $this->odoo->searchRead($lineModel, $lineDomain,
                    ['discount', 'price_unit', $qtyField],
                    ['offset' => $lineOffset, 'limit' => $lineBatch]);
                foreach ($lBatch as $l) {
                    $allLines[] = $l;
                }
                $lineOffset += $lineBatch;
            } while (count($lBatch) === $lineBatch);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('revenue line row-fetch failed, metrics degrade to zero', [
                    'error' => $e->getMessage(),
                ]);
                $allLines = [];
            }

            $outfits = count($allLines);

            foreach ($allLines as $line) {
                $disc = (float) ($line['discount'] ?? 0);
                if ($disc > 0) {
                    $price = (float) ($line['price_unit'] ?? 0);
                    $qty   = (float) ($line[$qtyField] ?? 0);
                    $discountAmt = $price * $qty * ($disc / 100.0);
                    $totalDiscount += $discountAmt;
                    $discountedOutfits++;
                }
            }
            } // /row-fetch fallback
        }

        // Ensure every working day in the window is represented so charts stay aligned
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
            if ((int) $cursor->format('w') === 0) {
                continue;
            }
            $dayKey = $cursor->format('Y-m-d');
            if (!array_key_exists($dayKey, $series)) {
                $series[$dayKey] = 0.0;
            }
        }
        ksort($series);
        return [
            'total'              => $total,
            'outfits'            => $outfits,
            'customers'          => $customers,
            'series'             => $series,
            'partner_revenue'    => $partnerRevenue,
            'partner_names'      => $partnerNames,
            'partner_orders'     => $partnerOrders,
            'partner_months'     => $partnerMonths,
            'doc_ids'            => $orderIds,
            'line_scope'         => $lineScope,
            'total_discount'     => $totalDiscount,
            'discounted_outfits' => $discountedOutfits,
        ];
    }

    /**
     * Top N customers by revenue in the current-year window. Builds from the
     * per-partner aggregate already produced by analyze(), so it avoids an
     * extra Odoo roundtrip.
     *
     * @param array $analysis The output of analyze()
     * @return array<int,array{id:int,name:string,revenue:float,share:float}>
     */
    public function topCustomers(array $analysis, int $limit = 5): array
    {
        $revMap  = $analysis['partner_revenue_cy'] ?? [];
        $nameMap = $analysis['partner_names_cy']   ?? [];
        $total   = (float) ($analysis['current']['total'] ?? 0.0);
        arsort($revMap);
        $out = [];
        $i = 0;
        foreach ($revMap as $pid => $rev) {
            if ($i++ >= $limit) break;
            $out[] = [
                'id'      => (int) $pid,
                'name'    => (string) ($nameMap[$pid] ?? ('Partner #' . $pid)),
                'revenue' => (float) $rev,
                'share'   => $total > 0 ? ((float) $rev) / $total * 100.0 : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Top N products by revenue in the current-year window. Only supported when
     * the revenue source is 'invoice' or 'sale' (pos.order.line has a different
     * shape and is skipped).
     *
     * @return array<int,array{id:int,name:string,revenue:float,qty:float,share:float}>
     */
    public function topProducts(array $analysis, int $limit = 5): array
    {
        if ($this->odoo instanceof \App\Odoo\NullOdooClient) return [];
        $docIds = $analysis['doc_ids_cy'] ?? [];
        $scope  = (array) ($analysis['line_scope_cy'] ?? []);
        if ($docIds === [] && $scope === []) {
            return [];
        }
        $total = (float) ($analysis['current']['total'] ?? 0.0);

        if ($this->source === 'invoice') {
            $lineModel  = 'account.move.line';
            $parent     = 'move_id';
            $amountField = 'price_subtotal';
            $qtyField    = 'quantity';
            $extraDomain = [['display_type', 'not in',
                ['line_section', 'line_note', 'payment_term', 'tax', 'cogs', 'rounding']]];
        } elseif ($this->source === 'sale') {
            $lineModel  = 'sale.order.line';
            $parent     = 'order_id';
            $amountField = 'price_subtotal';
            $qtyField    = 'product_uom_qty';
            $extraDomain = [['display_type', 'not in', ['line_section', 'line_note']]];
        } else {
            return [];
        }

        $domain = array_merge($docIds !== [] ? [[$parent, 'in', $docIds]] : $scope, $extraDomain);
        $lines = $this->odoo->searchRead($lineModel, $domain,
            ['product_id', $amountField, $qtyField], []);

        $agg = [];
        foreach ($lines as $l) {
            if (!isset($l['product_id']) || !is_array($l['product_id'])) continue;
            $pid   = (int) $l['product_id'][0];
            $pname = (string) ($l['product_id'][1] ?? '');
            $amt   = (float) ($l[$amountField] ?? 0);
            $qty   = (float) ($l[$qtyField] ?? 0);
            if (!isset($agg[$pid])) {
                $agg[$pid] = ['id' => $pid, 'name' => $pname, 'revenue' => 0.0, 'qty' => 0.0];
            }
            $agg[$pid]['revenue'] += $amt;
            $agg[$pid]['qty']     += $qty;
        }
        uasort($agg, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $out = [];
        $i = 0;
        foreach ($agg as $row) {
            if ($i++ >= $limit) break;
            $row['share'] = $total > 0 ? $row['revenue'] / $total * 100.0 : 0.0;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @return array{0:string,1:array,2:string,3:string,4:?string,5:?string}
     *   [model, domain, dateField, amountField, lineModel, lineParentField]
     */
    /**
     * Warehouse invoice facts usable for this tenant, or null to use Odoo.
     * Separate flag from bi.warehouse_gl: this reads wh_fact_invoice, not the
     * GL lines, so it earns its own gate rather than riding on one that
     * proved a different table.
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

    /** Day (Y-m-d) from a read_group row; __range preferred, label fallback. */
    private function dayOfGroup(array $row, string $field): ?string
    {
        $f = $row['__range'][$field]['from'] ?? $row['__range'][$field . ':day']['from'] ?? null;
        if (is_string($f) && strlen($f) >= 10) return substr($f, 0, 10);
        $label = $row[$field . ':day'] ?? null;
        if (is_string($label) && ($ts = strtotime($label)) !== false) return date('Y-m-d', $ts);
        return null;
    }

    private function modelConfig(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromS = $from->format('Y-m-d H:i:s');
        $toS   = $to->format('Y-m-d H:i:s');

        switch ($this->source) {
            case 'sale':
                // sale.order: verified against Odoo 13 -> 18.
                //  - 'done' state was removed in Odoo 17 (replaced by the `locked`
                //    boolean) but keeping it in the filter is harmless on newer
                //    versions and still matches locked orders on older ones.
                //  - amount_untaxed is HT (pre-VAT), stable across Odoo 13-18,
                //    matches the basis used by P&L / budget / class 70 GL.
                return [
                    'sale.order',
                    [
                        ['state', 'in', ['sale', 'done']],
                        ['date_order', '>=', $fromS],
                        ['date_order', '<=', $toS],
                    ],
                    'date_order',
                    'amount_untaxed',
                    'sale.order.line',
                    'order_id',
                ];
            case 'pos':
                // pos.order: states 'paid' / 'done' / 'invoiced' still exist in
                // Odoo 18. `date_order` is the canonical sale timestamp.
                // NOTE: amount_total here is TTC — `amount_untaxed` is not
                // a stable field on pos.order across Odoo versions, so we
                // leave the POS source on TTC until a POS-driven tenant
                // arrives and we can pick the right HT derivation per
                // their schema (subtract amount_tax in fetchWindow, or
                // sum price_subtotal on pos.order.line).
                return [
                    'pos.order',
                    [
                        ['state', 'in', ['paid', 'invoiced', 'done']],
                        ['date_order', '>=', $fromS],
                        ['date_order', '<=', $toS],
                    ],
                    'date_order',
                    'amount_total',
                    'pos.order.line',
                    'order_id',
                ];
            case 'invoice':
            default:
                // account.move: verified against Odoo 13 -> 18.
                //  - `move_type` (renamed from `type` back in v13) is stable.
                //  - `amount_untaxed_signed` is company-currency HT, signed
                //    (refunds come through negative automatically). HT is the
                //    OHADA P&L / IFRS basis — matches budgets and class 70 GL.
                //    Changed from amount_total_signed (TTC) on 2026-05-11 so
                //    the Dashboard revenue cell reconciles with /product-
                //    revenue-tsd and with the trial-balance class 70 sum.
                //  - `invoice_date` is the customer-facing document date, which
                //    is the correct basis for revenue recognition by calendar day
                //    (as opposed to `date`, which is the accounting/period date).
                return [
                    'account.move',
                    [
                        ['move_type', 'in', ['out_invoice', 'out_refund']],
                        ['state', '=', 'posted'],
                        ['invoice_date', '>=', $from->format('Y-m-d')],
                        ['invoice_date', '<=', $to->format('Y-m-d')],
                    ],
                    'invoice_date',
                    'amount_untaxed_signed',
                    'account.move.line',
                    'move_id',
                ];
        }
    }

    /**
     * Product profitability: compares actual revenue per product line against
     * the product's standard_price (cost) to derive margin.
     *
     * Returns ['most_profitable' => [...], 'least_profitable' => [...]]
     * Each entry: {id, name, revenue, cost, margin, margin_pct, qty}
     *
     * Only works for 'invoice' and 'sale' sources.
     *
     * @param array $analysis Output of analyze()
     */
    public function productProfitability(array $analysis, int $limit = 5): array
    {
        if ($this->odoo instanceof \App\Odoo\NullOdooClient) {
            return ['most_profitable' => [], 'least_profitable' => []];
        }
        $docIds = $analysis['doc_ids_cy'] ?? [];
        $scope  = (array) ($analysis['line_scope_cy'] ?? []);
        if ($docIds === [] && $scope === []) {
            return ['most_profitable' => [], 'least_profitable' => []];
        }

        // Determine line model and fields based on source
        if ($this->source === 'invoice') {
            $lineModel   = 'account.move.line';
            $parent      = 'move_id';
            $amountField = 'price_subtotal';
            $qtyField    = 'quantity';
            $extraDomain = [['display_type', 'not in',
                ['line_section', 'line_note', 'payment_term', 'tax', 'cogs', 'rounding']]];
        } elseif ($this->source === 'sale') {
            $lineModel   = 'sale.order.line';
            $parent      = 'order_id';
            $amountField = 'price_subtotal';
            $qtyField    = 'product_uom_qty';
            $extraDomain = [['display_type', 'not in', ['line_section', 'line_note']]];
        } else {
            return ['most_profitable' => [], 'least_profitable' => []];
        }

        // Fetch all product lines for the period's documents
        $domain = array_merge($docIds !== [] ? [[$parent, 'in', $docIds]] : $scope, $extraDomain);
        $lines = [];
        $offset = 0;
        $batchSize = 1000;
        do {
            $batch = $this->odoo->searchRead($lineModel, $domain,
                ['product_id', $amountField, $qtyField],
                ['offset' => $offset, 'limit' => $batchSize]);
            foreach ($batch as $l) {
                $lines[] = $l;
            }
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        // Aggregate revenue and qty per product
        $agg = []; // productId => [id, name, revenue, qty]
        foreach ($lines as $l) {
            if (!isset($l['product_id']) || !is_array($l['product_id'])) continue;
            $pid   = (int) $l['product_id'][0];
            $pname = (string) ($l['product_id'][1] ?? '');
            $amt   = (float) ($l[$amountField] ?? 0);
            $qty   = (float) ($l[$qtyField] ?? 0);
            if (!isset($agg[$pid])) {
                $agg[$pid] = ['id' => $pid, 'name' => $pname, 'revenue' => 0.0, 'qty' => 0.0];
            }
            $agg[$pid]['revenue'] += $amt;
            $agg[$pid]['qty']     += $qty;
        }

        if (empty($agg)) {
            return ['most_profitable' => [], 'least_profitable' => []];
        }

        // Fetch standard_price (cost) for each product from product.product
        $productIds = array_keys($agg);
        $costs = [];
        // Batch reads in chunks of 200 to avoid oversized RPC calls
        foreach (array_chunk($productIds, 200) as $chunk) {
            $products = $this->odoo->read('product.product', $chunk, ['standard_price']);
            foreach ($products as $p) {
                $costs[(int) $p['id']] = (float) ($p['standard_price'] ?? 0);
            }
        }

        // Compute margin per product
        $results = [];
        foreach ($agg as $pid => $row) {
            $unitCost  = $costs[$pid] ?? 0.0;
            $totalCost = $unitCost * $row['qty'];
            $margin    = $row['revenue'] - $totalCost;
            $marginPct = $row['revenue'] != 0 ? ($margin / $row['revenue']) * 100.0 : 0.0;

            $results[] = [
                'id'         => $row['id'],
                'name'       => $row['name'],
                'revenue'    => round($row['revenue'], 2),
                'cost'       => round($totalCost, 2),
                'margin'     => round($margin, 2),
                'margin_pct' => round($marginPct, 1),
                'qty'        => $row['qty'],
            ];
        }

        // Sort by margin descending for most profitable
        usort($results, fn($a, $b) => $b['margin'] <=> $a['margin']);
        $mostProfitable = array_slice($results, 0, $limit);

        // Sort by margin ascending for least profitable
        usort($results, fn($a, $b) => $a['margin'] <=> $b['margin']);
        $leastProfitable = array_slice($results, 0, $limit);

        return [
            'most_profitable'  => $mostProfitable,
            'least_profitable' => $leastProfitable,
        ];
    }

    /**
     * Top N products by total discount amount. For each product, returns the
     * aggregated discount amount and average discount percentage.
     *
     * Only works for 'invoice' and 'sale' sources.
     *
     * @return array<int,array{id:int,name:string,discount_amount:float,avg_discount_pct:float,qty:float,lines:int}>
     */
    public function topDiscountedProducts(array $analysis, int $limit = 5): array
    {
        if ($this->odoo instanceof \App\Odoo\NullOdooClient) return [];
        $docIds = $analysis['doc_ids_cy'] ?? [];
        $scope  = (array) ($analysis['line_scope_cy'] ?? []);
        if ($docIds === [] && $scope === []) {
            return [];
        }

        if ($this->source === 'invoice') {
            $lineModel   = 'account.move.line';
            $parent      = 'move_id';
            $qtyField    = 'quantity';
            $extraDomain = [['display_type', 'not in',
                ['line_section', 'line_note', 'payment_term', 'tax', 'cogs', 'rounding']]];
        } elseif ($this->source === 'sale') {
            $lineModel   = 'sale.order.line';
            $parent      = 'order_id';
            $qtyField    = 'product_uom_qty';
            $extraDomain = [['display_type', 'not in', ['line_section', 'line_note']]];
        } else {
            return [];
        }

        // Only fetch lines that actually have a discount > 0
        $domain = array_merge(
            array_merge($docIds !== [] ? [[$parent, 'in', $docIds]] : $scope, [['discount', '>', 0]]),
            $extraDomain
        );

        $lines = [];
        $offset = 0;
        $batchSize = 1000;
        do {
            $batch = $this->odoo->searchRead($lineModel, $domain,
                ['product_id', 'discount', 'price_unit', $qtyField],
                ['offset' => $offset, 'limit' => $batchSize]);
            foreach ($batch as $l) {
                $lines[] = $l;
            }
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        // Aggregate per product: total discount amount, sum of disc%, line count
        $agg = []; // pid => [id, name, discount_amount, disc_pct_sum, lines, qty]
        foreach ($lines as $l) {
            if (!isset($l['product_id']) || !is_array($l['product_id'])) continue;
            $pid   = (int) $l['product_id'][0];
            $pname = (string) ($l['product_id'][1] ?? '');
            $disc  = (float) ($l['discount'] ?? 0);
            $price = (float) ($l['price_unit'] ?? 0);
            $qty   = (float) ($l[$qtyField] ?? 0);
            $discAmt = $price * $qty * ($disc / 100.0);

            if (!isset($agg[$pid])) {
                $agg[$pid] = [
                    'id' => $pid, 'name' => $pname,
                    'discount_amount' => 0.0, 'disc_pct_sum' => 0.0,
                    'lines' => 0, 'qty' => 0.0,
                ];
            }
            $agg[$pid]['discount_amount'] += $discAmt;
            $agg[$pid]['disc_pct_sum']    += $disc;
            $agg[$pid]['lines']++;
            $agg[$pid]['qty'] += $qty;
        }

        // Sort by discount_amount descending
        uasort($agg, fn($a, $b) => $b['discount_amount'] <=> $a['discount_amount']);

        $out = [];
        $i = 0;
        foreach ($agg as $row) {
            if ($i++ >= $limit) break;
            $out[] = [
                'id'               => $row['id'],
                'name'             => $row['name'],
                'discount_amount'  => round($row['discount_amount'], 2),
                'avg_discount_pct' => $row['lines'] > 0
                    ? round($row['disc_pct_sum'] / $row['lines'], 1) : 0.0,
                'qty'              => $row['qty'],
                'lines'            => $row['lines'],
            ];
        }
        return $out;
    }

    private function workingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = $this->workingDaysExact($from, $to);
        return max(1, $days);
    }

    private function workingDaysExact(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return 0;
        }
        $days = 0;
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            if ((int) $date->format('w') === 0) {
                continue;
            }
            $days++;
        }
        return $days;
    }

    private function fetchCogsAndSalesDiscounts(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $accounts = $this->odoo->searchRead('account.account',
            ['|', ['code', 'like', '603%'], ['code', 'like', '7019%']],
            ['id', 'code'], []);

        $cogsIds = [];
        $discountIds = [];
        foreach ($accounts as $acc) {
            $code = (string) ($acc['code'] ?? '');
            $id   = (int) ($acc['id'] ?? 0);
            if ($id <= 0) continue;
            if (str_starts_with($code, '603')) {
                $cogsIds[] = $id;
            } elseif (str_starts_with($code, '7019')) {
                $discountIds[] = $id;
            }
        }

        $cogs = $this->sumAccounts($cogsIds, $from, $to);
        $discounts = $this->sumAccounts($discountIds, $from, $to);

        return $cogs + $discounts;
    }

    private function sumAccounts(array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        if ($accountIds === []) {
            return 0.0;
        }

        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain'  => [
                ['parent_state', '=', 'posted'],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['account_id', 'in', $accountIds],
            ],
            'fields'  => ['balance:sum', 'account_id'],
            'groupby' => ['account_id'],
            'lazy'    => false,
        ]);

        $sum = 0.0;
        foreach ($rows as $row) {
            $bal = (float) ($row['balance'] ?? 0.0);
            $sum += abs($bal);
        }
        return $sum;
    }

    private function buildBudgetPackage(\DateTimeImmutable $from, \DateTimeImmutable $to, array $actualSeries): array
    {
        $budgetSeries = [];
        $totals = [
            'revenue'   => 0.0,
            'outfits'   => 0.0,
            'discounts' => 0.0,
            'customers' => 0.0,
            'cost_base' => 0.0,
        ];
        $totalWorkingDays = 0;

        $monthCursor = new \DateTimeImmutable($from->format('Y-m-01'));
        $endMonth    = new \DateTimeImmutable($to->format('Y-m-01'));

        while ($monthCursor <= $endMonth) {
            $monthStartFull = $monthCursor;
            $monthEndFull   = $monthCursor->modify('last day of this month');
            $fullWorking    = $this->workingDaysExact($monthStartFull, $monthEndFull);
            if ($fullWorking === 0) {
                $monthCursor = $monthCursor->modify('+1 month');
                continue;
            }

            $rangeStart = $from > $monthStartFull ? $from : $monthStartFull;
            $rangeEnd   = $to < $monthEndFull ? $to : $monthEndFull;
            if ($rangeStart > $rangeEnd) {
                $monthCursor = $monthCursor->modify('+1 month');
                continue;
            }

            $dailyRevenue   = $this->budgetMonthlyRevenue   / $fullWorking;
            $dailyOutfits   = $this->budgetMonthlyOutfits   / $fullWorking;
            $dailyDiscounts = $this->budgetMonthlyDiscounts / $fullWorking;
            $dailyCustomers = $this->budgetMonthlyCustomers / $fullWorking;
            $dailyCostBase  = $this->budgetMonthlyCogs      / $fullWorking;

            for ($day = $rangeStart; $day <= $rangeEnd; $day = $day->modify('+1 day')) {
                if ((int) $day->format('w') === 0) {
                    continue;
                }
                $key = $day->format('Y-m-d');
                $budgetSeries[$key] = $dailyRevenue;
                $totals['revenue']   += $dailyRevenue;
                $totals['outfits']   += $dailyOutfits;
                $totals['discounts'] += $dailyDiscounts;
                $totals['customers'] += $dailyCustomers;
                $totals['cost_base'] += $dailyCostBase;
                $totalWorkingDays++;
            }

            $monthCursor = $monthCursor->modify('+1 month');
        }

        // Ensure alignment with actual series keys
        foreach ($actualSeries as $day => $_) {
            if (!isset($budgetSeries[$day])) {
                $budgetSeries[$day] = 0.0;
            }
        }
        ksort($budgetSeries);

        return [
            'series'       => $budgetSeries,
            'totals'       => $totals,
            'working_days' => $totalWorkingDays,
        ];
    }

    private function detectCurrency(): string
    {
        try {
            $user = $this->odoo->read('res.users', [$this->odoo->authenticate()], ['company_id']);
            $companyId = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $company = $this->odoo->read('res.company', [$companyId], ['currency_id']);
            $curId = isset($company[0]['currency_id'][0]) ? (int) $company[0]['currency_id'][0] : 0;
            if ($curId > 0) {
                $cur = $this->odoo->read('res.currency', [$curId], ['name', 'symbol']);
                if (isset($cur[0]['name'])) {
                    return (string) $cur[0]['name'];
                }
            }
        } catch (\Throwable $e) {
            // ignore and fall through
        }
        return 'XAF';
    }

    private function pct(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }
        return (($current - $previous) / abs($previous)) * 100.0;
    }

}
