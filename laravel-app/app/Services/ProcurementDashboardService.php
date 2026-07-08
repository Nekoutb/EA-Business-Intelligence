<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * Procurement Control Dashboard — one-page governance KPIs, computed
 * cumulative YTD **as at the last closed month-end** (metrics only move at
 * month-end close, per management's approved design).
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

    /** Last closed month-end relative to $today (7 Jul → 30 Jun). */
    public static function asAtDate(?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $today = $today ?? new DateTimeImmutable('today');
        return $today->modify('first day of this month')->modify('-1 day');
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
            'cadence' => 'Monthly', 'source' => 'Procurement system',
        ];
        $k['po_open_90'] = [
            'label' => 'Open POs aged > 90 days', 'unit' => '%',
            'actual' => $openTotal > 0 ? $gt90 / $openTotal * 100.0 : 0.0,
            'sub' => number_format($gt90) . ' of ' . number_format($openTotal) . ' open POs',
            'cadence' => 'Monthly', 'source' => 'Procurement system',
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
            'cadence' => 'Monthly', 'source' => 'Procurement system',
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
            'cadence' => 'Monthly', 'source' => 'Procurement system',
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
            'cadence' => 'Monthly', 'source' => 'AP + system',
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
            'cadence' => 'Quarterly', 'source' => 'Procurement system',
        ];
        $k['compliance'] = [
            'label' => 'PO compliance (bills backed by PO)', 'unit' => '%',
            'actual' => $bills > 0 ? $linked / $bills * 100.0 : 0.0,
            'sub' => number_format($linked) . ' of ' . number_format($bills) . ' bills',
            'cadence' => 'Monthly', 'source' => 'AP + system',
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
