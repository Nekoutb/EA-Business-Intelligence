<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Procurement analytics — *purchasing-lifecycle* lens, complementing
 * Payables (which lives in the *what-we-owe* lens).
 *
 * KPIs:
 *   P1. po_volume          Count + total of POs in period
 *                          Source: purchase.order where state in ('purchase','done')
 *                                  AND date_order in [from, to]
 *
 *   P2. po_cycle_time      Average days from PO creation (date_order) to
 *                          effective receipt (effective_date). Falls back to
 *                          stock.picking.date_done if effective_date is empty.
 *
 *   P3. po_compliance      Vendor bills booked WITHOUT a linked PO ÷ total
 *                          vendor bills posted in period. Detects "maverick
 *                          spend" — purchases bypassing the PO process.
 *                          Primary detection: account.move.line.purchase_line_id
 *                          Fallback:           purchase.order.invoice_ids reverse
 *
 * Period anchor:
 *   - P1 / P2 anchor on date_order (PO creation)
 *   - P3 anchors on invoice_date (the bill) — that's when the "did it
 *     follow process?" question gets answered.
 *
 * Each KPI helper traps its own exceptions and returns pending=true on
 * failure so a single broken query never 500s the whole page.
 */
final class ProcurementService
{
    private OdooClient $odoo;

    /** Cap fetched records per query (XML-RPC payload guardrail). */
    private const FETCH_CAP = 5000;

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

        $kpis = [
            'po_volume'      => $this->poVolume($cyFrom, $cyTo, $pyFrom, $pyTo),
            'po_cycle_time'  => $this->poCycleTime($cyFrom, $cyTo, $pyFrom, $pyTo),
            'po_compliance'  => $this->poCompliance($cyFrom, $cyTo, $pyFrom, $pyTo),
        ];

        return [
            'kpis'              => $kpis,
            'period_label'      => $periodLabel,
            'unlinked_bills'    => $this->topUnlinkedBills($cyFrom, $cyTo, 5),
            'pending_overall'   => array_reduce(
                $kpis,
                fn ($acc, $k) => $acc && !empty($k['pending']),
                true
            ),
        ];
    }

    // ─── P1: PO Volume & Spend ───────────────────────────────────────────────

    private function poVolume(
        \DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo,
        \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo
    ): array {
        try {
            $cyCount = $this->countPOs($cyFrom, $cyTo);
            $pyCount = $this->countPOs($pyFrom, $pyTo);
            $cyTotal = $this->sumPOAmount($cyFrom, $cyTo);

            return [
                'value'   => number_format($cyCount),
                'sub'     => $cyTotal > 0
                    ? ea_money_short($cyTotal, 'XAF') . ' total'
                    : '— spend —',
                'pending' => false,
                'vsPy'    => $pyCount > 0 ? 100.0 * ($cyCount - $pyCount) / $pyCount : null,
            ];
        } catch (\Throwable $e) {
            return $this->failed('PO volume', $e);
        }
    }

    // ─── P2: PO Cycle Time ───────────────────────────────────────────────────

    private function poCycleTime(
        \DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo,
        \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo
    ): array {
        try {
            $cyDays = $this->avgCycleDays($cyFrom, $cyTo);
            $pyDays = $this->avgCycleDays($pyFrom, $pyTo);

            if ($cyDays === null) {
                return [
                    'value'   => '— —',
                    'sub'     => 'No closed POs in period',
                    'pending' => true,
                ];
            }

            return [
                'value'   => round($cyDays, 1) . ' days',
                'sub'     => 'PO creation → effective receipt',
                'pending' => false,
                // Lower cycle time = better, so invert the sign for vs-PY.
                'vsPy'    => $pyDays !== null ? -1.0 * ($cyDays - $pyDays) / max($pyDays, 0.0001) * 100.0 : null,
            ];
        } catch (\Throwable $e) {
            return $this->failed('PO cycle time', $e);
        }
    }

    /**
     * Returns avg(effective_date - date_order) in days for POs in period.
     * Excludes rows where effective_date is null (PO not yet received).
     */
    private function avgCycleDays(\DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        $pos = $this->odoo->searchRead(
            'purchase.order',
            [
                ['state', 'in', ['purchase', 'done']],
                ['date_order', '>=', $from->format('Y-m-d 00:00:00')],
                ['date_order', '<=', $to->format('Y-m-d 23:59:59')],
            ],
            ['id', 'date_order', 'effective_date'],
            ['limit' => self::FETCH_CAP]
        );

        $diffs = [];
        foreach ($pos as $p) {
            $start = (string) ($p['date_order'] ?? '');
            $end   = (string) ($p['effective_date'] ?? '');
            if ($start === '' || $end === '' || $end === false) continue;
            try {
                $d1 = new \DateTimeImmutable($start);
                $d2 = new \DateTimeImmutable($end);
                $diffs[] = max(0, (int) $d1->diff($d2)->days);
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (empty($diffs)) return null;
        return array_sum($diffs) / count($diffs);
    }

    // ─── P3: PO Compliance ───────────────────────────────────────────────────

    private function poCompliance(
        \DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo,
        \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo
    ): array {
        try {
            $cy = $this->complianceForWindow($cyFrom, $cyTo);
            $py = $this->complianceForWindow($pyFrom, $pyTo);

            if ($cy['total'] === 0) {
                return [
                    'value'   => '— —',
                    'sub'     => 'No vendor bills in period',
                    'pending' => true,
                ];
            }

            $cyNonComplianceRate = 100.0 * $cy['unlinked'] / $cy['total'];
            $pyNonComplianceRate = $py['total'] > 0 ? 100.0 * $py['unlinked'] / $py['total'] : null;

            return [
                'value'   => number_format($cyNonComplianceRate, 1) . '%',
                'sub'     => sprintf('%d of %d bills booked without a PO', $cy['unlinked'], $cy['total']),
                'pending' => false,
                // Lower is better — invert sign so green = improvement.
                'vsPy'    => $pyNonComplianceRate !== null
                    ? -1.0 * ($cyNonComplianceRate - $pyNonComplianceRate)
                    : null,
                'breakdown' => [
                    'total'    => $cy['total'],
                    'linked'   => $cy['linked'],
                    'unlinked' => $cy['unlinked'],
                ],
            ];
        } catch (\Throwable $e) {
            return $this->failed('PO compliance', $e);
        }
    }

    /**
     * Returns ['total' => int, 'linked' => int, 'unlinked' => int] of vendor
     * bills posted in the window.
     *
     * Detection:
     *   - Primary: account.move.line.purchase_line_id (bill line tied to PO line)
     *   - Fallback: purchase.order.invoice_ids reverse-lookup
     *
     * Both paths yield "set of bill IDs that came from a PO". Anything outside
     * that set is non-compliant maverick spend.
     */
    private function complianceForWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $bills = $this->odoo->searchRead(
            'account.move',
            [
                ['move_type', '=', 'in_invoice'],
                ['state', '=', 'posted'],
                ['invoice_date', '>=', $from->format('Y-m-d')],
                ['invoice_date', '<=', $to->format('Y-m-d')],
            ],
            ['id'],
            ['limit' => self::FETCH_CAP]
        );
        $billIds = array_column($bills, 'id');
        $total = count($billIds);
        if ($total === 0) {
            return ['total' => 0, 'linked' => 0, 'unlinked' => 0];
        }

        $linkedSet = $this->resolveLinkedBillIds($billIds);
        $linked    = count($linkedSet);

        return [
            'total'    => $total,
            'linked'   => $linked,
            'unlinked' => $total - $linked,
        ];
    }

    /**
     * Returns the subset of $billIds that have at least one PO link.
     * Tries line-level detection first, falls back to PO reverse-lookup.
     */
    private function resolveLinkedBillIds(array $billIds): array
    {
        if (empty($billIds)) return [];

        // Primary: any move line with purchase_line_id set.
        try {
            $lines = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['move_id', 'in', $billIds],
                    ['purchase_line_id', '!=', false],
                ],
                ['move_id'],
                ['limit' => self::FETCH_CAP * 10]
            );
            $linkedSet = [];
            foreach ($lines as $line) {
                $mid = is_array($line['move_id'] ?? null)
                    ? $line['move_id'][0]
                    : ($line['move_id'] ?? null);
                if ($mid) $linkedSet[$mid] = true;
            }
            // Even if zero rows came back, the call succeeded — that's a
            // valid "no bills had POs" answer; return it.
            return array_keys($linkedSet);
        } catch (\Throwable $e) {
            // primary path unsupported → fall through to fallback
        }

        // Fallback: reverse via purchase.order.invoice_ids.
        try {
            $pos = $this->odoo->searchRead(
                'purchase.order',
                [['invoice_ids', 'in', $billIds]],
                ['invoice_ids'],
                ['limit' => self::FETCH_CAP]
            );
            $billsAsSet = array_flip($billIds);
            $linkedSet  = [];
            foreach ($pos as $po) {
                foreach ((array) ($po['invoice_ids'] ?? []) as $id) {
                    if (isset($billsAsSet[$id])) {
                        $linkedSet[$id] = true;
                    }
                }
            }
            return array_keys($linkedSet);
        } catch (\Throwable $e) {
            // Both paths failed — surface as non-compliant unknown by
            // returning empty, but the caller's KPI will still report a
            // total. The page will visibly show 100% non-compliant which
            // the schema-pending banner will explain. We don't throw
            // because that would break the wider page.
            return [];
        }
    }

    /**
     * Top-N highest-value vendor bills WITHOUT a PO link, for the
     * drill-down table on the page.
     */
    private function topUnlinkedBills(\DateTimeImmutable $from, \DateTimeImmutable $to, int $topN): array
    {
        try {
            $bills = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type', '=', 'in_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                ['id', 'name', 'invoice_date', 'partner_id', 'amount_total_signed'],
                ['limit' => self::FETCH_CAP, 'order' => 'amount_total_signed desc']
            );
            if (empty($bills)) return [];

            $billIds = array_column($bills, 'id');
            $linkedSet = array_flip($this->resolveLinkedBillIds($billIds));

            $unlinked = array_filter($bills, fn ($b) => !isset($linkedSet[$b['id']]));
            $unlinked = array_values($unlinked);
            // Already sorted desc by amount; just slice.
            return array_map(function ($b) {
                $partner = is_array($b['partner_id'] ?? null) ? $b['partner_id'][1] : '—';
                return [
                    'id'           => $b['id'],
                    'name'         => (string) ($b['name'] ?? ''),
                    'partner'      => (string) $partner,
                    'invoice_date' => (string) ($b['invoice_date'] ?? ''),
                    'amount'       => (float) ($b['amount_total_signed'] ?? 0),
                ];
            }, array_slice($unlinked, 0, $topN));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─── plumbing ────────────────────────────────────────────────────────────

    private function countPOs(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->odoo->searchCount('purchase.order', [
            ['state', 'in', ['purchase', 'done']],
            ['date_order', '>=', $from->format('Y-m-d 00:00:00')],
            ['date_order', '<=', $to->format('Y-m-d 23:59:59')],
        ]);
    }

    private function sumPOAmount(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        try {
            $rows = $this->odoo->callModel('purchase.order', 'read_group', [
                'domain' => [
                    ['state', 'in', ['purchase', 'done']],
                    ['date_order', '>=', $from->format('Y-m-d 00:00:00')],
                    ['date_order', '<=', $to->format('Y-m-d 23:59:59')],
                ],
                'fields'  => ['amount_total:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            return (float) ($rows[0]['amount_total'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
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
