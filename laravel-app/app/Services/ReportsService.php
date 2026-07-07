<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * ReportsService — day-level operational reports for the Reports section.
 *
 *   1. dailySales($date)     — sales from posted customer invoices issued on a
 *                              day: outfit count, amount, customers, split into
 *                              CASH (settled) vs CREDIT (open) by payment_state.
 *   2. dailyInventory($date) — stock moves (state=done) on a day, split into
 *                              inward / outward, valued at product standard cost,
 *                              plus the current on-hand value at standard cost.
 *   3. monthActivity(y, m)   — days in a month that have sales (for the calendar).
 *
 * Currency-agnostic numbers; the controller/view format with the tenant ccy.
 */
final class ReportsService
{
    public function __construct(private OdooClient $odoo) {}

    /** Sales made on $date (Y-m-d) from posted customer invoices. */
    public function dailySales(string $date): array
    {
        $invs = $this->odoo->searchRead('account.move',
            [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'], ['invoice_date', '=', $date]],
            ['name', 'partner_id', 'amount_total_signed', 'amount_residual_signed', 'payment_state'],
            ['limit' => 1000, 'order' => 'amount_total_signed desc']);

        $total = 0.0; $cashTotal = 0.0; $creditTotal = 0.0; $cashCount = 0; $creditCount = 0;
        $rows = []; $ids = [];
        foreach ($invs as $i) {
            $amt   = (float) ($i['amount_total_signed'] ?? 0);
            $resid = (float) ($i['amount_residual_signed'] ?? 0);
            $ps    = (string) ($i['payment_state'] ?? '');
            $isCash = in_array($ps, ['paid', 'in_payment'], true);   // settled = cash sale
            $total += $amt;
            if ($isCash) { $cashTotal += $amt; $cashCount++; }
            else         { $creditTotal += $amt; $creditCount++; }
            $ids[] = (int) $i['id'];
            $rows[] = [
                'invoice'  => (string) ($i['name'] ?? ''),
                'customer' => is_array($i['partner_id'] ?? null) ? (string) ($i['partner_id'][1] ?? '—') : '—',
                'amount'   => $amt,
                'paid'     => $amt - $resid,
                'open'     => $resid,
                'type'     => $isCash ? 'cash' : 'credit',
            ];
        }

        // Outfits sold = Σ quantity on the product lines of those invoices.
        $outfits = 0.0;
        if ($ids) {
            try {
                $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['move_id', 'in', $ids], ['display_type', '=', 'product']], 'fields' => ['quantity:sum'], 'groupby' => [], 'lazy' => false]);
                $outfits = (float) ($g[0]['quantity'] ?? 0);
            } catch (\Throwable $e) { /* leave 0 */ }
        }

        return [
            'date'          => $date,
            'invoice_count' => count($invs),
            'outfits'       => $outfits,
            'total'         => $total,
            'cash_total'    => $cashTotal,  'cash_count'   => $cashCount,
            'credit_total'  => $creditTotal,'credit_count' => $creditCount,
            'rows'          => $rows,
        ];
    }

    /** Stock movements on $date (Y-m-d), valued at standard cost. */
    public function dailyInventory(string $date): array
    {
        $from = $date . ' 00:00:00'; $to = $date . ' 23:59:59';
        $direction = function (array $extra) use ($from, $to): array {
            $dom = array_merge([['state', '=', 'done'], ['date', '>=', $from], ['date', '<=', $to]], $extra);
            $g = $this->odoo->callModel('stock.move', 'read_group', ['domain' => $dom, 'fields' => ['product_qty:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
            $byProd = []; $totQty = 0.0;
            foreach ((array) $g as $r) {
                $p = $r['product_id'] ?? false;
                if (is_array($p)) { $byProd[(int) $p[0]] = ['name' => (string) ($p[1] ?? '?'), 'qty' => (float) ($r['product_qty'] ?? 0)]; $totQty += (float) ($r['product_qty'] ?? 0); }
            }
            $val = 0.0;
            if ($byProd) {
                $prods = $this->odoo->read('product.product', array_keys($byProd), ['standard_price']);
                $cost = []; foreach ($prods as $p) $cost[(int) $p['id']] = (float) ($p['standard_price'] ?? 0);
                foreach ($byProd as $pid => &$row) { $row['cost'] = $cost[$pid] ?? 0; $row['value'] = $row['qty'] * $row['cost']; $val += $row['value']; }
                unset($row);
                usort($byProd, fn ($a, $b) => $b['value'] <=> $a['value']);
            }
            return ['qty' => $totQty, 'value' => $val, 'product_count' => count($byProd), 'lines' => array_values($byProd)];
        };

        $inward  = $direction([['location_dest_id.usage', '=', 'internal'], ['location_id.usage', '!=', 'internal']]);
        $outward = $direction([['location_id.usage', '=', 'internal'], ['location_dest_id.usage', '!=', 'internal']]);

        $onHand = $this->onHandAsOf($date);

        return [
            'date'          => $date,
            'inward'        => $inward,
            'outward'       => $outward,
            'net_qty'       => $inward['qty'] - $outward['qty'],
            'net_value'     => $inward['value'] - $outward['value'],
            'on_hand_value' => $onHand['value'],
            'on_hand_qty'   => $onHand['qty'],
        ];
    }

    /**
     * On-hand inventory at the END of $date, valued at current standard cost.
     * Reconstructed = current on-hand quantity − net internal moves made AFTER
     * $date (so the figure moves as you navigate the calendar, rather than
     * always showing "now"). stock.valuation.layer is absent on this Odoo, so
     * we roll back the live stock.quant by the subsequent moves.
     *
     * @return array{value: float, qty: float}
     */
    private function onHandAsOf(string $date): array
    {
        try {
            // Current on-hand quantity per product (internal locations).
            $gq = $this->odoo->callModel('stock.quant', 'read_group', ['domain' => [['location_id.usage', '=', 'internal']], 'fields' => ['quantity:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
            $qtyNow = [];
            foreach ((array) $gq as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) $qtyNow[(int) $p[0]] = (float) ($r['quantity'] ?? 0); }
            if (empty($qtyNow)) return ['value' => 0.0, 'qty' => 0.0];

            // Net internal movement (qty) per product for moves done AFTER $date.
            $net = [];
            $accumulate = function (array $extra, float $sign) use (&$net, $date) {
                $g = $this->odoo->callModel('stock.move', 'read_group', ['domain' => array_merge([['state', '=', 'done'], ['date', '>', $date . ' 23:59:59']], $extra), 'fields' => ['product_qty:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
                foreach ((array) $g as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) $net[(int) $p[0]] = ($net[(int) $p[0]] ?? 0) + $sign * (float) ($r['product_qty'] ?? 0); }
            };
            $accumulate([['location_dest_id.usage', '=', 'internal'], ['location_id.usage', '!=', 'internal']], 1.0);   // inward
            $accumulate([['location_id.usage', '=', 'internal'], ['location_dest_id.usage', '!=', 'internal']], -1.0);  // outward

            // Costs for every on-hand product.
            $std = [];
            foreach (array_chunk(array_keys($qtyNow), 300) as $chunk) {
                foreach ($this->odoo->read('product.product', $chunk, ['standard_price']) as $p) $std[(int) $p['id']] = (float) ($p['standard_price'] ?? 0);
            }

            $val = 0.0; $qty = 0.0;
            foreach ($qtyNow as $pid => $q) {
                $asOf = $q - ($net[$pid] ?? 0);   // roll back the post-date moves
                $qty += $asOf;
                $val += $asOf * ($std[$pid] ?? 0);
            }
            return ['value' => $val, 'qty' => $qty];
        } catch (\Throwable $e) { return ['value' => 0.0, 'qty' => 0.0]; }
    }

    /** Days in (year, month) that have posted sales — for the calendar dots. */
    public function monthActivity(int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $g = $this->odoo->callModel('account.move', 'read_group', ['domain' => [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'], ['invoice_date', '>=', $from], ['invoice_date', '<=', $to]], 'fields' => ['amount_total_signed:sum'], 'groupby' => ['invoice_date:day'], 'lazy' => false]);
        $out = [];
        foreach ((array) $g as $r) {
            $day = null;
            foreach (['invoice_date', 'invoice_date:day'] as $k) if (isset($r['__range'][$k]['from'])) { $day = substr((string) $r['__range'][$k]['from'], 0, 10); break; }
            if ($day) $out[$day] = ['count' => (int) ($r['__count'] ?? 0), 'amount' => (float) ($r['amount_total_signed'] ?? 0)];
        }
        return $out;
    }

    /**
     * Operating expenses for a day + cumulative month-to-date, from posted GL
     * lines on OHADA class-6 accounts EXCLUDING class 60 (purchases and stock
     * variation — inventory movements are reported separately, per the daily
     * report spec). Amounts are net debit (balance), so credit notes and
     * reversals reduce the figure.
     *
     * @return array{date:string, lines:array<int,array{account:string,amount:float}>, day_total:float, mtd_total:float, month_label:string}
     */
    public function dailyExpenses(string $date): array
    {
        $d          = new \DateTimeImmutable($date);
        $monthStart = $d->modify('first day of this month')->format('Y-m-d');
        $empty      = ['date' => $date, 'lines' => [], 'day_total' => 0.0, 'mtd_total' => 0.0, 'month_label' => $d->format('F Y')];

        try {
            $accounts = $this->odoo->searchRead('account.account', [['code', '=like', '6%']], ['id', 'code', 'name'], ['limit' => 1000, 'order' => 'code asc']);
        } catch (\Throwable $e) { return $empty; }

        $ids = []; $label = [];
        foreach ($accounts as $a) {
            $code = (string) ($a['code'] ?? '');
            if (!str_starts_with($code, '6') || str_starts_with($code, '60')) continue;
            $ids[] = (int) $a['id'];
            $label[(int) $a['id']] = trim($code . ' ' . (string) ($a['name'] ?? ''));
        }
        if (empty($ids)) return $empty;

        // Expense lines posted on the day, by account.
        $lines = []; $dayTotal = 0.0;
        try {
            $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['date', '=', $date]], 'fields' => ['balance:sum'], 'groupby' => ['account_id'], 'lazy' => false]);
            foreach ((array) $g as $r) {
                $aid = is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : 0;
                $amt = (float) ($r['balance'] ?? 0);
                if (abs($amt) < 0.005) continue;
                $lines[] = ['account' => $label[$aid] ?? ('#' . $aid), 'amount' => $amt];
                $dayTotal += $amt;
            }
            usort($lines, static fn ($a, $b) => abs($b['amount']) <=> abs($a['amount']));
        } catch (\Throwable $e) { /* leave empty */ }

        // Cumulative for the month (1st → the report day, inclusive).
        $mtd = 0.0;
        try {
            $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['date', '>=', $monthStart], ['date', '<=', $date]], 'fields' => ['balance:sum'], 'groupby' => [], 'lazy' => false]);
            $mtd = (float) ($g[0]['balance'] ?? 0);
        } catch (\Throwable $e) { /* leave 0 */ }

        return ['date' => $date, 'lines' => $lines, 'day_total' => $dayTotal, 'mtd_total' => $mtd, 'month_label' => $d->format('F Y')];
    }

    /** Best-effort company currency code. */
    public function currency(): string
    {
        try {
            $uid = $this->odoo->authenticate();
            $u = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid = is_array($u[0]['company_id'] ?? null) ? (int) $u[0]['company_id'][0] : 1;
            $co = $this->odoo->read('res.company', [$cid], ['currency_id']);
            $curRef = $co[0]['currency_id'] ?? null;
            if (is_array($curRef) && !empty($curRef[0])) {
                $cur = $this->odoo->read('res.currency', [(int) $curRef[0]], ['name']);
                return (string) ($cur[0]['name'] ?? 'XAF');
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }
}
