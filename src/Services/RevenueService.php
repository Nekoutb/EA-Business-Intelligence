<?php
declare(strict_types=1);

namespace EA\BI\Services;

use EA\BI\Odoo\OdooClient;

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

    public function __construct(OdooClient $odoo, string $source = 'invoice')
    {
        $this->odoo = $odoo;
        $this->source = strtolower($source);
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

        $current = [
            'total'        => $cy['total'],
            'outfits'      => $cy['outfits'],
            'customers'    => $cy['customers'],
            'per_day'      => $days > 0 ? $cy['total'] / $days : 0.0,
            'per_outfit'   => $cy['outfits']   > 0 ? $cy['total'] / $cy['outfits']   : 0.0,
            'per_customer' => $cy['customers'] > 0 ? $cy['total'] / $cy['customers'] : 0.0,
            'series'       => $cy['series'],
        ];
        $previous = [
            'total'        => $py['total'],
            'outfits'      => $py['outfits'],
            'customers'    => $py['customers'],
            'per_day'      => $days > 0 ? $py['total'] / $days : 0.0,
            'per_outfit'   => $py['outfits']   > 0 ? $py['total'] / $py['outfits']   : 0.0,
            'per_customer' => $py['customers'] > 0 ? $py['total'] / $py['customers'] : 0.0,
            'series'       => $py['series'],
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
            'doc_ids_cy'         => $cy['doc_ids'],
        ];
    }

    /**
     * @return array{total:float,outfits:int,customers:int,series:array<string,float>,
     *   partner_revenue:array<int,float>,partner_names:array<int,string>,doc_ids:int[]}
     */
    private function fetchWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$model, $domain, $dateField, $amountField, $lineModel, $lineParentField] = $this->modelConfig($from, $to);

        // Pull only what we need. Paginate in chunks to be safe on large datasets.
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

        $total   = 0.0;
        $series  = [];
        $orderIds = [];
        $partnerRevenue = [];   // partnerId => revenue
        $partnerNames   = [];   // partnerId => display name
        foreach ($records as $r) {
            // amount_total_signed (invoices) is already signed in company currency,
            // so refunds come through as negative automatically.
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
            }
        }
        $customers = count($partnerRevenue);

        // Count outfits = number of customer-facing product lines on those documents.
        // NOTE: Odoo 16+ changed account.move.line.display_type so product lines are
        // 'product' (not False as in v15-). We therefore EXCLUDE non-product display
        // types, which is forward- and backward-compatible across Odoo 13 -> 18+.
        $outfits = 0;
        if ($orderIds !== [] && $lineModel !== null) {
            $lineDomain = [[$lineParentField, 'in', $orderIds]];
            if ($lineModel === 'account.move.line') {
                $lineDomain[] = ['display_type', 'not in',
                    ['line_section', 'line_note', 'payment_term', 'tax', 'cogs', 'rounding']];
            } elseif ($lineModel === 'sale.order.line') {
                $lineDomain[] = ['display_type', 'not in', ['line_section', 'line_note']];
            }
            // pos.order.line has no display_type field: all rows are product lines.
            $outfits = $this->odoo->searchCount($lineModel, $lineDomain);
        }

        ksort($series);
        return [
            'total'           => $total,
            'outfits'         => $outfits,
            'customers'       => $customers,
            'series'          => $series,
            'partner_revenue' => $partnerRevenue,
            'partner_names'   => $partnerNames,
            'doc_ids'         => $orderIds,
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
        $docIds = $analysis['doc_ids_cy'] ?? [];
        if ($docIds === []) {
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

        $domain = array_merge([[$parent, 'in', $docIds]], $extraDomain);
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
                return [
                    'sale.order',
                    [
                        ['state', 'in', ['sale', 'done']],
                        ['date_order', '>=', $fromS],
                        ['date_order', '<=', $toS],
                    ],
                    'date_order',
                    'amount_total',
                    'sale.order.line',
                    'order_id',
                ];
            case 'pos':
                // pos.order: states 'paid' / 'done' / 'invoiced' still exist in
                // Odoo 18. `date_order` is the canonical sale timestamp.
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
                //  - `amount_total_signed` is company-currency, signed (refunds
                //    come through negative automatically).
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
                    'amount_total_signed',
                    'account.move.line',
                    'move_id',
                ];
        }
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
        return 'USD';
    }

    private function pct(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }
        return (($current - $previous) / abs($previous)) * 100.0;
    }

}
