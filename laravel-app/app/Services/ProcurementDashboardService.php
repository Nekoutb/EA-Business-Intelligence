<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * Procurement Control Dashboard — one-page governance KPIs, computed
 * cumulative YTD **as at the end of the last completed week** (metrics are
 * monitored weekly per management's direction; monthlyTrends() additionally
 * provides the Jan→now monthly evolution for the Procurement detail page).
 *
 * KPIs (actuals; targets are admin-set and stored via BudgetService):
 *   po_open_60     % of open POs aged > 60 days (of open POs at as-at date)
 *   po_open_90     % of open POs aged > 90 days
 *   po_processing  Avg business days RFQ created → PO confirmed (proxy — no
 *                  requisition module exists in this Odoo)
 *   po_after_fact  % of invoice-linked POs whose order date is later than
 *                  their earliest posted supplier invoice
 *   dpo            Days payable outstanding = AP(401+408, ≤ as-at) ÷
 *                  class-6 (ex 69) expenses YTD × 365 (running cumulative)
 *   exempt         % of posted vendor bills with no PO-linked line
 *   compliance     % of posted vendor bills backed by a PO (complement)
 *
 * `emergency` (manual exceptions log) is injected by the controller from
 * tenant storage — it has no Odoo source.
 */
final class ProcurementDashboardService
{
    private const FETCH_CAP = 2000;

    public function __construct(private OdooClient $odoo) {}

    /**
     * End of the last completed week — the Sunday strictly before $today
     * (a Thursday 9 Jul → Sunday 5 Jul; a Sunday itself → the previous
     * Sunday, since the current week isn't complete until end of day).
     */
    public static function asAtDate(?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $today = $today ?? new DateTimeImmutable('today');
        return $today->modify('last sunday');
    }

    public function analyze(DateTimeImmutable $asAt): array
    {
        $yearStart = $asAt->modify('first day of January')->format('Y-m-d');
        $asAtD     = $asAt->format('Y-m-d');
        $asAtDT    = $asAt->format('Y-m-d 23:59:59');

        $k = [];

        // ── Open-PO ageing (state=purchase, ordered on/before as-at) ──────
        $openTotal = $gt60 = $gt90 = 0;
        try {
            $openBase = [['state', '=', 'purchase'], ['date_order', '<=', $asAtDT]];
            $openTotal = $this->odoo->searchCount('purchase.order', $openBase);
            $gt60 = $this->odoo->searchCount('purchase.order', array_merge($openBase, [['date_order', '<', $asAt->modify('-60 days')->format('Y-m-d 00:00:00')]]));
            $gt90 = $this->odoo->searchCount('purchase.order', array_merge($openBase, [['date_order', '<', $asAt->modify('-90 days')->format('Y-m-d 00:00:00')]]));
        } catch (\Throwable $e) { /* leave zeros */ }
        $k['po_open_60'] = [
            'label' => 'Open POs aged > 60 days', 'unit' => '%',
            'actual' => $openTotal > 0 ? $gt60 / $openTotal * 100.0 : 0.0,
            'sub' => number_format($gt60) . ' of ' . number_format($openTotal) . ' open POs',
            'cadence' => 'Weekly', 'source' => 'Procurement system',
            'hint' => 'Share of open purchase orders raised more than 60 days ago and still not fully delivered or billed. Old open orders usually mean stalled deliveries, or orders that should be closed or cancelled.',
        ];
        $k['po_open_90'] = [
            'label' => 'Open POs aged > 90 days', 'unit' => '%',
            'actual' => $openTotal > 0 ? $gt90 / $openTotal * 100.0 : 0.0,
            'sub' => number_format($gt90) . ' of ' . number_format($openTotal) . ' open POs',
            'cadence' => 'Weekly', 'source' => 'Procurement system',
            'hint' => 'Share of open purchase orders raised more than 90 days ago — the most severe stragglers. Each one should be chased with the supplier, closed, or cancelled.',
        ];

        // ── Processing time: RFQ created → PO confirmed, business days ────
        $avgBiz = null; $confirmed = 0;
        try {
            $pos = $this->odoo->searchRead('purchase.order',
                [['state', 'in', ['purchase', 'done']], ['date_approve', '>=', $yearStart . ' 00:00:00'], ['date_approve', '<=', $asAtDT]],
                ['create_date', 'date_approve'], ['limit' => self::FETCH_CAP]);
            $confirmed = count($pos);
            $sum = 0.0; $n = 0;
            foreach ($pos as $p) {
                if (empty($p['create_date']) || empty($p['date_approve'])) continue;
                try {
                    $a = new DateTimeImmutable((string) $p['create_date']);
                    $b = new DateTimeImmutable((string) $p['date_approve']);
                } catch (\Throwable $e) { continue; }
                $sum += $this->businessDaysBetween($a, $b);
                $n++;
            }
            if ($n > 0) $avgBiz = $sum / $n;
        } catch (\Throwable $e) { /* leave null */ }
        $k['po_processing'] = [
            'label' => 'PO processing time (RFQ → PO)', 'unit' => 'd',
            'actual' => $avgBiz !== null ? round($avgBiz, 1) : null,
            'sub' => 'average business days, ' . number_format($confirmed) . ' POs YTD · proxy',
            'cadence' => 'Weekly', 'source' => 'Procurement system',
            'hint' => 'Average number of working days between a purchase request being created in the system and its confirmation as a purchase order. Measures how quickly procurement turns a need into a committed order.',
        ];

        // ── After-the-fact POs (PO date > earliest posted invoice date) ───
        $withInv = 0; $after = 0; $offenders = [];
        try {
            $pos = $this->odoo->searchRead('purchase.order',
                [['state', 'in', ['purchase', 'done']], ['date_order', '>=', $yearStart . ' 00:00:00'], ['date_order', '<=', $asAtDT], ['invoice_ids', '!=', false]],
                ['name', 'partner_id', 'date_order', 'invoice_ids'], ['limit' => self::FETCH_CAP]);
            $invIds = [];
            foreach ($pos as $p) foreach ((array) ($p['invoice_ids'] ?? []) as $iid) $invIds[(int) $iid] = true;
            $invDate = [];
            foreach (array_chunk(array_keys($invIds), 500) as $chunk) {
                foreach ($this->odoo->searchRead('account.move', [['id', 'in', $chunk], ['state', '=', 'posted']], ['invoice_date'], ['limit' => 1000]) as $inv) {
                    $invDate[(int) $inv['id']] = (string) ($inv['invoice_date'] ?? '');
                }
            }
            foreach ($pos as $p) {
                $earliest = null;
                foreach ((array) ($p['invoice_ids'] ?? []) as $iid) {
                    $d = $invDate[(int) $iid] ?? '';
                    if ($d !== '' && ($earliest === null || $d < $earliest)) $earliest = $d;
                }
                if ($earliest === null) continue;
                $withInv++;
                $poDate = substr((string) ($p['date_order'] ?? ''), 0, 10);
                if ($poDate > $earliest) {
                    $after++;
                    if (count($offenders) < 15) {
                        $offenders[] = [
                            'name'    => (string) ($p['name'] ?? ''),
                            'partner' => is_array($p['partner_id'] ?? null) ? (string) ($p['partner_id'][1] ?? '—') : '—',
                            'po_date' => $poDate, 'inv_date' => $earliest,
                            'gap'     => (int) ((strtotime($poDate) - strtotime($earliest)) / 86400),
                        ];
                    }
                }
            }
            usort($offenders, static fn ($a, $b) => $b['gap'] <=> $a['gap']);
        } catch (\Throwable $e) { /* leave zeros */ }
        $k['po_after_fact'] = [
            'label' => 'POs created after the fact', 'unit' => '%',
            'actual' => $withInv > 0 ? $after / $withInv * 100.0 : 0.0,
            'sub' => number_format($after) . ' of ' . number_format($withInv) . ' invoiced POs',
            'cadence' => 'Weekly', 'source' => 'Procurement system',
            'hint' => 'Share of purchase orders dated after their supplier invoice — the order was raised to regularise a purchase that had already happened, bypassing prior approval.',
        ];

        // ── DPO — running cumulative YTD. Numerator = open supplier-bill
        // balance (Σ amount_residual_signed over posted vendor bills/refunds,
        // sign-flipped: vendor documents carry negative signed amounts) —
        // the SAME basis as the Payables page headline, so the two pages
        // agree. The GL 401/408 net balance is unusable on this ledger: it
        // nets to a debit (unreconciled receipts/advances) and would read as
        // zero AP. Denominator = class-6 (ex 69) GL expenses YTD, ×365.
        $dpo = null; $ap = 0.0; $exp = 0.0;
        try {
            $g = $this->odoo->callModel('account.move', 'read_group', ['domain' => [['move_type', 'in', ['in_invoice', 'in_refund']], ['state', '=', 'posted']], 'fields' => ['amount_residual_signed:sum'], 'groupby' => [], 'lazy' => false]);
            $ap = max(0.0, -1.0 * (float) ($g[0]['amount_residual_signed'] ?? 0));

            $accounts = $this->odoo->searchRead('account.account', [['code', '=like', '6%']], ['id', 'code'], []);
            $expIds = [];
            foreach ($accounts as $a) {
                $code = (string) ($a['code'] ?? ''); $id = (int) ($a['id'] ?? 0);
                if ($id > 0 && $code !== '' && !str_starts_with($code, '69')) $expIds[] = $id;
            }
            if ($expIds) {
                $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $expIds], ['date', '>=', $yearStart], ['date', '<=', $asAtD]], 'fields' => ['balance:sum'], 'groupby' => [], 'lazy' => false]);
                $exp = (float) ($g[0]['balance'] ?? 0);                    // debit-normal
            }
            if ($ap > 0 && $exp > 0) $dpo = $ap / $exp * 365.0;
        } catch (\Throwable $e) { /* leave null */ }
        $k['dpo'] = [
            'label' => 'Days payable outstanding (DPO)', 'unit' => 'd',
            'actual' => $dpo !== null ? round($dpo, 1) : null,
            'sub' => 'running cumulative, YTD',
            'cadence' => 'Weekly', 'source' => 'AP + system',
            'hint' => 'Days Payable Outstanding — roughly how many days of expenses are sitting unpaid with suppliers (open supplier bills relative to year-to-date expenses). Shows how quickly the company settles its suppliers; same basis as the Payables page.',
        ];

        // ── Bills with / without a PO (count basis) ───────────────────────
        $bills = 0; $linked = 0;
        try {
            $bills = $this->odoo->searchCount('account.move', [['move_type', '=', 'in_invoice'], ['state', '=', 'posted'], ['invoice_date', '>=', $yearStart], ['invoice_date', '<=', $asAtD]]);
            $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['move_id.move_type', '=', 'in_invoice'], ['parent_state', '=', 'posted'], ['move_id.invoice_date', '>=', $yearStart], ['move_id.invoice_date', '<=', $asAtD], ['purchase_line_id', '!=', false]], 'fields' => [], 'groupby' => ['move_id'], 'lazy' => false]);
            $linked = count((array) $g);
        } catch (\Throwable $e) { /* leave zeros */ }
        $k['exempt'] = [
            'label' => 'Purchases exempt from a PO', 'unit' => '%',
            'actual' => $bills > 0 ? ($bills - $linked) / $bills * 100.0 : 0.0,
            'sub' => number_format(max(0, $bills - $linked)) . ' of ' . number_format($bills) . ' bills, YTD',
            'cadence' => 'Weekly', 'source' => 'Procurement system',
            'hint' => 'Share of supplier invoices that are not backed by any purchase order. Purchases made outside the PO process escape prior approval and budget control ("maverick spend").',
        ];
        $k['compliance'] = [
            'label' => 'PO compliance (bills backed by PO)', 'unit' => '%',
            'actual' => $bills > 0 ? $linked / $bills * 100.0 : 0.0,
            'sub' => number_format($linked) . ' of ' . number_format($bills) . ' bills',
            'cadence' => 'Weekly', 'source' => 'AP + system',
            'hint' => 'Share of supplier invoices backed by an approved purchase order — the mirror of exempt purchases. Higher means better purchasing discipline: commitments are approved before the money is spent.',
        ];

        return [
            'as_at'     => $asAtD,
            'kpis'      => $k,
            'offenders' => $offenders,
            'meta'      => [
                'open_pos'      => $openTotal,
                'confirmed_ytd' => $confirmed,
                'bills_ytd'     => $bills,
            ],
        ];
    }

    /**
     * Monthly evolution of the dashboard KPIs since January — one point per
     * closed month (cumulative YTD basis, mirroring the dashboard's
     * definitions) for the Procurement detail page's graphs.
     *
     * Open-PO ageing is necessarily based on currently-open orders (this
     * Odoo keeps no PO state history); DPO reuses the anchored running
     * series from PayablesService so it matches the Payables page. The
     * emergency series is injected by the controller (manual annual count).
     *
     * @return array{year:int, labels:string[], series:array<string, array<int, float|null>>}
     */
    public function monthlyTrends(?DateTimeImmutable $today = null): array
    {
        $today    = $today ?? new DateTimeImmutable('today');
        $mEndLast = $today->modify('first day of this month')->modify('-1 day');
        $year     = (int) $mEndLast->format('Y');
        $M        = max(1, (int) $mEndLast->format('n'));
        $yearStart = sprintf('%04d-01-01', $year);

        $labels = []; $monthEnds = [];
        for ($m = 1; $m <= $M; $m++) {
            $monthEnds[$m] = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)))->modify('last day of this month');
            $labels[] = $monthEnds[$m]->format('M');
        }
        $blank  = array_fill(0, $M, null);
        $series = [
            'po_open_60' => $blank, 'po_open_90' => $blank, 'po_processing' => $blank,
            'po_after_fact' => $blank, 'dpo' => $blank, 'emergency' => $blank,
            'exempt' => $blank, 'compliance' => $blank,
        ];

        // Open-PO ageing per month-end (currently-open set, aged vs each cut-off).
        try {
            $open = $this->odoo->searchRead('purchase.order', [['state', '=', 'purchase']], ['date_order'], ['limit' => self::FETCH_CAP]);
            for ($m = 1; $m <= $M; $m++) {
                $end = $monthEnds[$m]->format('Y-m-d');
                $c60 = $monthEnds[$m]->modify('-60 days')->format('Y-m-d');
                $c90 = $monthEnds[$m]->modify('-90 days')->format('Y-m-d');
                $tot = 0; $n60 = 0; $n90 = 0;
                foreach ($open as $p) {
                    $d = substr((string) ($p['date_order'] ?? ''), 0, 10);
                    if ($d === '' || $d > $end) continue;
                    $tot++;
                    if ($d < $c60) $n60++;
                    if ($d < $c90) $n90++;
                }
                if ($tot > 0) {
                    $series['po_open_60'][$m - 1] = round($n60 / $tot * 100, 1);
                    $series['po_open_90'][$m - 1] = round($n90 / $tot * 100, 1);
                }
            }
        } catch (\Throwable $e) { /* leave nulls */ }

        // Processing time — cumulative YTD average at each month-end.
        try {
            $pos = $this->odoo->searchRead('purchase.order',
                [['state', 'in', ['purchase', 'done']], ['date_approve', '>=', $yearStart . ' 00:00:00'], ['date_approve', '<=', $mEndLast->format('Y-m-d 23:59:59')]],
                ['create_date', 'date_approve'], ['limit' => self::FETCH_CAP]);
            $rows = [];
            foreach ($pos as $p) {
                if (empty($p['create_date']) || empty($p['date_approve'])) continue;
                try {
                    $a = new DateTimeImmutable((string) $p['create_date']);
                    $b = new DateTimeImmutable((string) $p['date_approve']);
                } catch (\Throwable $e) { continue; }
                $rows[] = ['m' => (int) $b->format('n'), 'd' => $this->businessDaysBetween($a, $b)];
            }
            for ($m = 1; $m <= $M; $m++) {
                $sum = 0.0; $n = 0;
                foreach ($rows as $r) { if ($r['m'] <= $m) { $sum += $r['d']; $n++; } }
                if ($n > 0) $series['po_processing'][$m - 1] = round($sum / $n, 1);
            }
        } catch (\Throwable $e) { /* leave nulls */ }

        // After-the-fact — cumulative YTD share at each month-end.
        try {
            $pos = $this->odoo->searchRead('purchase.order',
                [['state', 'in', ['purchase', 'done']], ['date_order', '>=', $yearStart . ' 00:00:00'], ['date_order', '<=', $mEndLast->format('Y-m-d 23:59:59')], ['invoice_ids', '!=', false]],
                ['date_order', 'invoice_ids'], ['limit' => self::FETCH_CAP]);
            $invIds = [];
            foreach ($pos as $p) foreach ((array) ($p['invoice_ids'] ?? []) as $iid) $invIds[(int) $iid] = true;
            $invDate = [];
            foreach (array_chunk(array_keys($invIds), 500) as $chunk) {
                foreach ($this->odoo->searchRead('account.move', [['id', 'in', $chunk], ['state', '=', 'posted']], ['invoice_date'], ['limit' => 1000]) as $inv) {
                    $invDate[(int) $inv['id']] = (string) ($inv['invoice_date'] ?? '');
                }
            }
            $items = [];
            foreach ($pos as $p) {
                $earliest = null;
                foreach ((array) ($p['invoice_ids'] ?? []) as $iid) {
                    $d = $invDate[(int) $iid] ?? '';
                    if ($d !== '' && ($earliest === null || $d < $earliest)) $earliest = $d;
                }
                if ($earliest === null) continue;
                $poDate = substr((string) ($p['date_order'] ?? ''), 0, 10);
                $items[] = ['m' => (int) substr($poDate, 5, 2), 'after' => $poDate > $earliest];
            }
            for ($m = 1; $m <= $M; $m++) {
                $tot = 0; $af = 0;
                foreach ($items as $it) { if ($it['m'] <= $m) { $tot++; if ($it['after']) $af++; } }
                if ($tot > 0) $series['po_after_fact'][$m - 1] = round($af / $tot * 100, 1);
            }
        } catch (\Throwable $e) { /* leave nulls */ }

        // Exempt / compliance — cumulative YTD share at each month-end.
        try {
            $bills = $this->odoo->searchRead('account.move',
                [['move_type', '=', 'in_invoice'], ['state', '=', 'posted'], ['invoice_date', '>=', $yearStart], ['invoice_date', '<=', $mEndLast->format('Y-m-d')]],
                ['invoice_date'], ['limit' => self::FETCH_CAP]);
            $linked = [];
            $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['move_id.move_type', '=', 'in_invoice'], ['parent_state', '=', 'posted'], ['move_id.invoice_date', '>=', $yearStart], ['move_id.invoice_date', '<=', $mEndLast->format('Y-m-d')], ['purchase_line_id', '!=', false]], 'fields' => [], 'groupby' => ['move_id'], 'lazy' => false]);
            foreach ((array) $g as $r) { $mv = $r['move_id'] ?? false; if (is_array($mv)) $linked[(int) $mv[0]] = true; }
            $items = [];
            foreach ($bills as $b) {
                $d = (string) ($b['invoice_date'] ?? '');
                if ($d === '') continue;
                $items[] = ['m' => (int) substr($d, 5, 2), 'linked' => isset($linked[(int) $b['id']])];
            }
            for ($m = 1; $m <= $M; $m++) {
                $tot = 0; $ln = 0;
                foreach ($items as $it) { if ($it['m'] <= $m) { $tot++; if ($it['linked']) $ln++; } }
                if ($tot > 0) {
                    $series['compliance'][$m - 1] = round($ln / $tot * 100, 1);
                    $series['exempt'][$m - 1]     = round(($tot - $ln) / $tot * 100, 1);
                }
            }
        } catch (\Throwable $e) { /* leave nulls */ }

        // DPO — anchored running series (same figures as the Payables page).
        try {
            $g = $this->odoo->callModel('account.move', 'read_group', ['domain' => [['move_type', 'in', ['in_invoice', 'in_refund']], ['state', '=', 'posted']], 'fields' => ['amount_residual_signed:sum'], 'groupby' => [], 'lazy' => false]);
            $totalAp = max(0.0, -1.0 * (float) ($g[0]['amount_residual_signed'] ?? 0));
            $accounts = $this->odoo->searchRead('account.account', [['code', '=like', '6%']], ['id', 'code'], []);
            $expIds = [];
            foreach ($accounts as $a) {
                $code = (string) ($a['code'] ?? ''); $id = (int) ($a['id'] ?? 0);
                if ($id > 0 && $code !== '' && !str_starts_with($code, '69')) $expIds[] = $id;
            }
            $exp = 0.0;
            if ($expIds) {
                $g = $this->odoo->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $expIds], ['date', '>=', $yearStart], ['date', '<=', $mEndLast->format('Y-m-d')]], 'fields' => ['balance:sum'], 'groupby' => [], 'lazy' => false]);
                $exp = (float) ($g[0]['balance'] ?? 0);
            }
            if ($totalAp > 0 && $exp > 0) {
                $ser = (new PayablesService($this->odoo))->dpoRunningSeries($totalAp, $exp, 365, $year, $today);
                foreach (array_values($ser) as $i => $p) {
                    if ($i < $M) $series['dpo'][$i] = $p['dpo'] ?? null;
                }
            }
        } catch (\Throwable $e) { /* leave nulls */ }

        return ['year' => $year, 'labels' => $labels, 'series' => $series];
    }

    /** Whole business days (Mon–Fri) between two datetimes, ≥ 0. */
    private function businessDaysBetween(DateTimeImmutable $a, DateTimeImmutable $b): float
    {
        if ($b <= $a) return 0.0;
        $days = 0;
        $cur = $a->setTime(0, 0);
        $end = $b->setTime(0, 0);
        $guard = 0;
        while ($cur < $end && $guard++ < 1000) {
            if ((int) $cur->format('N') <= 5) $days++;
            $cur = $cur->modify('+1 day');
        }
        return (float) $days;
    }
}
