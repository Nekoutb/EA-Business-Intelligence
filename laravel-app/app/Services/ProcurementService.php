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
 *                          earliest linked invoice_date when effective_date is
 *                          empty (covers POs billed without a logged stock
 *                          receipt). PO is excluded if neither timestamp exists.
 *
 *   P3. po_compliance      Vendor bills booked WITHOUT a linked PO ÷ total
 *                          vendor bills posted in period. Detects "maverick
 *                          spend" — purchases bypassing the PO process.
 *                          Primary detection: account.move.line.purchase_line_id
 *                          Fallback:           purchase.order.invoice_ids reverse
 *
 *   P4. total_spend        Total billed spend = sum(amount_total_signed) of
 *                          posted in_invoice in period. Complements P1 (which
 *                          measures *committed* PO spend). Surfaces the
 *                          actual liability incurred regardless of whether
 *                          there was a PO upstream.
 *
 * Drill-downs:
 *   - Open POs sandbox: count of state='purchase' POs, avg/max age in days,
 *     and the top 10 oldest as a remediation target list.
 *   - Top 10 highest-value bills booked WITHOUT a PO link (paired with P3).
 *   - Top 10 suppliers by billed spend (paired with P4).
 *
 * Period anchor:
 *   - P1 / P2 anchor on date_order (PO creation)
 *   - P3 / P4 anchor on invoice_date (the bill) — that's when the
 *     "did it follow process?" / "what did it cost?" questions crystallise.
 *   - Open POs sandbox is age-anchored on today (not period-bound).
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
            'total_spend'    => $this->totalSpend($cyFrom, $cyTo, $pyFrom, $pyTo),
        ];

        return [
            'kpis'              => $kpis,
            'period_label'      => $periodLabel,
            'open_pos'          => $this->openPOsSandbox(),
            'unlinked_bills'    => $this->topUnlinkedBills($cyFrom, $cyTo, 10),
            'top_suppliers'     => $this->topSuppliersBySpend($cyFrom, $cyTo, 10),
            'trend'             => $this->procurementTrend($today, $period),
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
     * Avg(end - date_order) in days for POs in period, where:
     *   end := effective_date  (Odoo's computed actual receipt — preferred)
     *       || earliest linked invoice's invoice_date  (fallback)
     *
     * Both paths are valid "PO is effectively closed" anchors. The fallback
     * picks up POs where stock receipt wasn't logged but the bill was,
     * which is common when the supplier ships against a PO and the
     * accounting team enters the bill before warehouse logs it.
     *
     * POs with neither timestamp populated are excluded — they're still
     * in flight, no cycle to measure yet.
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
            ['id', 'date_order', 'effective_date', 'invoice_ids'],
            ['limit' => self::FETCH_CAP]
        );

        if (empty($pos)) return null;

        // Build a list of invoice IDs we'll need to look up the earliest
        // posted invoice_date for, but only for POs missing effective_date.
        $invoiceIdToPoId = [];
        foreach ($pos as $p) {
            $end = $p['effective_date'] ?? null;
            if (!empty($end) && $end !== false) continue;
            foreach ((array) ($p['invoice_ids'] ?? []) as $invId) {
                $invoiceIdToPoId[$invId] = $p['id'];
            }
        }

        $earliestInvoiceByPo = [];
        if (!empty($invoiceIdToPoId)) {
            try {
                $invs = $this->odoo->searchRead(
                    'account.move',
                    [
                        ['id', 'in', array_keys($invoiceIdToPoId)],
                        ['state', '=', 'posted'],
                    ],
                    ['id', 'invoice_date'],
                    ['limit' => self::FETCH_CAP]
                );
                foreach ($invs as $inv) {
                    $poId = $invoiceIdToPoId[$inv['id']] ?? null;
                    $d    = $inv['invoice_date'] ?? null;
                    if (!$poId || empty($d)) continue;
                    if (!isset($earliestInvoiceByPo[$poId]) || $d < $earliestInvoiceByPo[$poId]) {
                        $earliestInvoiceByPo[$poId] = $d;
                    }
                }
            } catch (\Throwable $e) {
                // ignore — POs without effective_date stay excluded
            }
        }

        $diffs = [];
        foreach ($pos as $p) {
            $start = (string) ($p['date_order'] ?? '');
            $end   = (string) ($p['effective_date'] ?? '');
            if ($end === '' || $end === '0' || $p['effective_date'] === false) {
                $end = $earliestInvoiceByPo[$p['id']] ?? '';
            }
            if ($start === '' || $end === '') continue;
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

    // ─── Open POs sandbox ────────────────────────────────────────────────────

    /**
     * Snapshot of POs that are confirmed but not yet closed (state='purchase').
     * Returns headline numbers + the top-10 oldest for action.
     *
     * NOT period-filtered — this is "what's open right now", regardless of
     * when it was created (that's the whole point).
     */
    private function openPOsSandbox(): array
    {
        try {
            $pos = $this->odoo->searchRead(
                'purchase.order',
                [['state', '=', 'purchase']],
                ['id', 'name', 'partner_id', 'date_order', 'amount_total'],
                ['limit' => self::FETCH_CAP, 'order' => 'date_order asc']
            );
            if (empty($pos)) {
                return [
                    'count'           => 0,
                    'avg_age_days'    => null,
                    'oldest_age_days' => null,
                    'oldest'          => [],
                ];
            }

            $today = new \DateTimeImmutable('today');
            $rows  = [];
            $ages  = [];
            foreach ($pos as $p) {
                $d = (string) ($p['date_order'] ?? '');
                if ($d === '') continue;
                try {
                    $created = new \DateTimeImmutable($d);
                } catch (\Throwable $e) {
                    continue;
                }
                $age = (int) $today->diff($created)->days;
                $ages[] = $age;
                $partner = is_array($p['partner_id'] ?? null) ? $p['partner_id'][1] : '—';
                $rows[]  = [
                    'id'         => $p['id'],
                    'name'       => (string) ($p['name'] ?? ''),
                    'partner'    => (string) $partner,
                    'date_order' => $d,
                    'age_days'   => $age,
                    'amount'     => (float) ($p['amount_total'] ?? 0),
                ];
            }

            // Two views over the same set:
            //   oldest        — sorted by age desc (chase-list)
            //   top_by_amount — sorted by amount desc (where the money is)
            $byAge    = $rows;
            usort($byAge, fn ($a, $b) => $b['age_days'] <=> $a['age_days']);

            $byAmount = $rows;
            usort($byAmount, fn ($a, $b) => $b['amount'] <=> $a['amount']);

            return [
                'count'           => count($pos),
                'avg_age_days'    => empty($ages) ? null : array_sum($ages) / count($ages),
                'oldest_age_days' => empty($ages) ? null : max($ages),
                'oldest'          => array_slice($byAge, 0, 10),
                'top_by_amount'   => array_slice($byAmount, 0, 10),
            ];
        } catch (\Throwable $e) {
            return [
                'count'           => null,
                'avg_age_days'    => null,
                'oldest_age_days' => null,
                'oldest'          => [],
                'top_by_amount'   => [],
                'error'           => $e->getMessage(),
            ];
        }
    }

    // ─── P4: Total Vendor Spend (billed) ─────────────────────────────────────

    private function totalSpend(
        \DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo,
        \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo
    ): array {
        try {
            $cyCount  = $this->countVendorBills($cyFrom, $cyTo);
            $pyCount  = $this->countVendorBills($pyFrom, $pyTo);
            // Net of taxes — uses amount_untaxed_signed instead of
            // amount_total_signed so the figure represents pure goods/
            // services spend without VAT distortion. abs() applied because
            // amount_untaxed_signed is *negative* for vendor bills (P&L
            // direction signed) — we want positive numbers throughout the
            // user-facing report.
            $cyAmount = abs($this->sumVendorBillAmount($cyFrom, $cyTo));
            $pyAmount = abs($this->sumVendorBillAmount($pyFrom, $pyTo));

            if ($cyCount === 0) {
                return [
                    'value'   => '— —',
                    'sub'     => 'No vendor bills in period',
                    'pending' => true,
                ];
            }

            return [
                // Disclose the absolute value (not the M/K abbreviated form
                // — we want exact spend visible).
                'value'   => number_format($cyAmount) . ' XAF',
                'sub'     => sprintf('%s bills posted · net of taxes', number_format($cyCount)),
                'pending' => false,
                // Going up = more spend → flag negative (red) on the
                // assumption that controlling spend is the default posture.
                'vsPy'    => $pyAmount > 0
                    ? -1.0 * 100.0 * ($cyAmount - $pyAmount) / $pyAmount
                    : null,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Total spend', $e);
        }
    }

    /**
     * Top-N suppliers by billed spend (net of taxes) in window. For each
     * supplier, also returns the dominant expense account their bills are
     * recorded against — answers "what kind of spend is this?".
     *
     * Used as the dropdown detail next to the Total Spend card.
     *
     * Sorting & sign handling:
     *   amount_untaxed_signed on vendor bills is *negative* by convention
     *   (signed in P&L direction). To get "biggest spend first" robustly,
     *   we fetch all groups and sort in PHP by abs() desc — this
     *   is independent of any future Odoo sign-convention surprise.
     */
    private function topSuppliersBySpend(\DateTimeImmutable $from, \DateTimeImmutable $to, int $topN): array
    {
        try {
            $rows = $this->odoo->callModel('account.move', 'read_group', [
                'domain' => [
                    ['move_type', '=', 'in_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                'fields'  => ['partner_id', 'amount_untaxed_signed:sum'],
                'groupby' => ['partner_id'],
                'lazy'    => false,
                // No orderby/limit at Odoo level — sort & slice in PHP.
            ]);

            $all = [];
            foreach ($rows as $r) {
                $partnerArr  = $r['partner_id'] ?? null;
                $partnerId   = is_array($partnerArr) ? (int) $partnerArr[0] : null;
                $partnerName = is_array($partnerArr) ? (string) $partnerArr[1] : '—';
                $amountRaw   = (float) ($r['amount_untaxed_signed'] ?? 0);

                // Odoo's read_group returns the group count under __count
                // when lazy=false; older versions surface it as
                // <field>_count. Try both.
                $count = (int) ($r['__count'] ?? $r['partner_id_count'] ?? 0);

                $all[] = [
                    'partner_id' => $partnerId,
                    'partner'    => $partnerName,
                    'count'      => $count,
                    'amount'     => abs($amountRaw),  // positive for display
                    'account'    => null,             // filled below
                ];
            }

            // Biggest spend first, regardless of sign convention.
            usort($all, fn ($a, $b) => $b['amount'] <=> $a['amount']);
            $top = array_slice($all, 0, $topN);

            // Look up dominant expense account only for the top N — keeps
            // total query cost bounded even on tenants with hundreds of
            // suppliers.
            foreach ($top as &$s) {
                if ($s['partner_id']) {
                    $s['account'] = $this->dominantExpenseAccount(
                        $s['partner_id'], $from, $to
                    );
                }
            }
            unset($s);

            return $top;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * For a given supplier, return the name of the expense account where
     * the largest share of their bills (by debit amount) is posted in the
     * window. Returns null if no expense lines were found.
     *
     * Approach: pull all this supplier's posted vendor-bill IDs in the
     * window, then group their move lines by account_id taking only the
     * debit (expense) side, and pick the account with the highest sum.
     */
    private function dominantExpenseAccount(
        int $partnerId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): ?string {
        try {
            $bills = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type', '=', 'in_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                    ['partner_id', '=', $partnerId],
                ],
                ['id'],
                ['limit' => self::FETCH_CAP]
            );
            $moveIds = array_column($bills, 'id');
            if (empty($moveIds)) return null;

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['move_id', 'in', $moveIds],
                    ['debit', '>', 0], // expense leg of a vendor bill
                ],
                'fields'  => ['account_id', 'debit:sum'],
                'groupby' => ['account_id'],
                'orderby' => 'debit desc',
                'lazy'    => false,
                'limit'   => 1,
            ]);
            if (empty($rows)) return null;
            $accField = $rows[0]['account_id'] ?? null;
            return is_array($accField) ? (string) $accField[1] : null;
        } catch (\Throwable $e) {
            return null;
        }
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

            // Headline = compliance rate (% of bills WITH a linked PO).
            // Higher is better; vs-PY direction is naturally positive=good.
            $cyComplianceRate = 100.0 * $cy['linked'] / $cy['total'];
            $pyComplianceRate = $py['total'] > 0 ? 100.0 * $py['linked'] / $py['total'] : null;

            return [
                'value'   => number_format($cyComplianceRate, 1) . '%',
                'sub'     => sprintf('%d of %d bills have a PO', $cy['linked'], $cy['total']),
                'pending' => false,
                'vsPy'    => $pyComplianceRate !== null
                    ? ($cyComplianceRate - $pyComplianceRate)
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

    private function countVendorBills(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->odoo->searchCount('account.move', [
            ['move_type', '=', 'in_invoice'],
            ['state', '=', 'posted'],
            ['invoice_date', '>=', $from->format('Y-m-d')],
            ['invoice_date', '<=', $to->format('Y-m-d')],
        ]);
    }

    /**
     * Sum of vendor bills in the window — net of taxes.
     * (amount_untaxed_signed = bill subtotal in company currency.)
     */
    private function sumVendorBillAmount(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        try {
            $rows = $this->odoo->callModel('account.move', 'read_group', [
                'domain' => [
                    ['move_type', '=', 'in_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $from->format('Y-m-d')],
                    ['invoice_date', '<=', $to->format('Y-m-d')],
                ],
                'fields'  => ['amount_untaxed_signed:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            return (float) ($rows[0]['amount_untaxed_signed'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    // ─── Trend chart (top of page) ───────────────────────────────────────────

    /**
     * Trend axis for the chart at the top of /procurement.
     * Three switchable series — meaning fixed across granularities:
     *
     *   po_compliance — % of bills WITH a PO in the bucket
     *   total_spend   — sum of abs(amount_untaxed_signed) of in_invoice
     *                   posted in the bucket (net of taxes)
     *   open_pos      — count of POs that were open at the bucket's end
     *                   (date_order ≤ EOB AND (effective_date IS NULL OR > EOB)).
     *                   Buckets whose end is in the future return null.
     *
     * Granularity follows the period filter:
     *   - YTD → 12 monthly buckets for the selected year (Jan…Dec)
     *   - MTD / Month → 4 weekly buckets within the month
     *                   (Week 1: 1–7, Week 2: 8–14, Week 3: 15–21,
     *                   Week 4: 22–EOM)
     */
    private function procurementTrend(\DateTimeImmutable $today, array $period): array
    {
        $type = strtolower((string) ($period['type'] ?? 'ytd'));
        $isWeekly = ($type === 'mtd' || $type === 'month');

        $buckets = $isWeekly
            ? $this->trendWeeklyBuckets($today, $period)
            : $this->trendMonthlyBuckets($today, $period);

        // Service-side outputs, sized to bucket count.
        $n = count($buckets);
        $compliancePct = array_fill(0, $n, null);
        $spend         = array_fill(0, $n, 0.0);
        $billCount     = array_fill(0, $n, 0);
        $linkedCount   = array_fill(0, $n, 0);
        $openPOs       = array_fill(0, $n, null);

        // Earliest bucket start / latest bucket end define the bills query
        // window; buckets outside [today] still bucketise correctly via
        // findBucketIndex.
        $axisStart = $buckets[0]['start'];
        $axisEnd   = end($buckets)['end'];
        // Don't pull future bills (none exist anyway).
        $billCutoff = $axisEnd > $today ? $today : $axisEnd;

        // Series 1 + 2: bills/PO-link/spend per bucket
        try {
            $bills = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type', '=', 'in_invoice'],
                    ['state', '=', 'posted'],
                    ['invoice_date', '>=', $axisStart->format('Y-m-d')],
                    ['invoice_date', '<=', $billCutoff->format('Y-m-d')],
                ],
                ['id', 'invoice_date', 'amount_untaxed_signed'],
                ['limit' => self::FETCH_CAP]
            );

            $billIds   = array_column($bills, 'id');
            $linkedSet = array_flip($this->resolveLinkedBillIds($billIds));

            foreach ($bills as $b) {
                $bDate = (string) ($b['invoice_date'] ?? '');
                if ($bDate === '') continue;
                try {
                    $bDt = new \DateTimeImmutable($bDate);
                } catch (\Throwable $e) { continue; }

                $idx = $this->findBucketIndex($bDt, $buckets);
                if ($idx === null) continue;

                $billCount[$idx]++;
                if (isset($linkedSet[$b['id']])) {
                    $linkedCount[$idx]++;
                }
                $spend[$idx] += abs((float) ($b['amount_untaxed_signed'] ?? 0));
            }
            foreach ($billCount as $i => $cnt) {
                $compliancePct[$i] = $cnt > 0
                    ? round(100.0 * $linkedCount[$i] / $cnt, 1)
                    : null;
            }
        } catch (\Throwable $e) {
            // leave arrays as nulls/zeros — chart still renders empty axes
        }

        // Series 3: Open POs at end of each bucket.
        try {
            $pos = $this->odoo->searchRead(
                'purchase.order',
                [
                    ['state', 'in', ['purchase', 'done']],
                    ['date_order', '<=', $today->format('Y-m-d 23:59:59')],
                ],
                ['id', 'date_order', 'effective_date'],
                ['limit' => self::FETCH_CAP]
            );

            $parsed = [];
            foreach ($pos as $p) {
                $do = (string) ($p['date_order'] ?? '');
                if ($do === '') continue;
                try {
                    $doDt = new \DateTimeImmutable($do);
                } catch (\Throwable $e) { continue; }
                $ed = $p['effective_date'] ?? null;
                $edDt = null;
                if (!empty($ed) && $ed !== false) {
                    try {
                        $edDt = new \DateTimeImmutable((string) $ed);
                    } catch (\Throwable $e) { /* leave null */ }
                }
                $parsed[] = ['order' => $doDt, 'eff' => $edDt];
            }

            foreach ($buckets as $i => $bucket) {
                $bucketEnd = $bucket['end'];
                // Future bucket → no data point (preserves "we haven't got
                // here yet" intent visually rather than plotting a flat line).
                if ($bucketEnd > $today) {
                    $openPOs[$i] = null;
                    continue;
                }
                $count = 0;
                foreach ($parsed as $p) {
                    if ($p['order'] > $bucketEnd) continue;          // not yet created
                    if ($p['eff'] !== null && $p['eff'] <= $bucketEnd) continue; // already received
                    $count++;
                }
                $openPOs[$i] = $count;
            }
        } catch (\Throwable $e) {
            // leave at default fills
        }

        return [
            'granularity' => $isWeekly ? 'week' : 'month',
            'buckets'     => array_map(fn ($b) => ['label' => $b['label']], $buckets),
            'series'      => [
                'po_compliance' => $compliancePct,
                'total_spend'   => $spend,
                'open_pos'      => $openPOs,
            ],
            'meta' => [
                'po_compliance' => [
                    'label'  => 'PO Compliance %',
                    'format' => 'pct',
                    'unit'   => '%',
                ],
                'total_spend' => [
                    'label'  => 'Total Vendor Spend',
                    'format' => 'money',
                    'unit'   => 'XAF',
                ],
                'open_pos' => [
                    'label'  => 'Open POs',
                    'format' => 'count',
                    'unit'   => '',
                ],
            ],
        ];
    }

    /**
     * 12 monthly buckets for the selected year (defaults to current year).
     * Each bucket carries its DateTime start/end so the bill loop can find
     * its index without parsing month strings.
     */
    private function trendMonthlyBuckets(\DateTimeImmutable $today, array $period): array
    {
        $year = (int) ($period['year'] ?? $today->format('Y'));
        $buckets = [];
        for ($m = 1; $m <= 12; $m++) {
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $m));
            $end   = $start->modify('last day of this month')->setTime(23, 59, 59);
            $buckets[] = [
                'start' => $start,
                'end'   => $end,
                'label' => $start->format('M Y'),
            ];
        }
        return $buckets;
    }

    /**
     * 4 weekly buckets inside a single month:
     *   Week 1: days 1–7
     *   Week 2: days 8–14
     *   Week 3: days 15–21
     *   Week 4: days 22 → end of month
     *
     * Resolves the month from period:
     *   - type='mtd'   → current month, current year (today's month)
     *   - type='month' → period['year'] + period['month']
     */
    private function trendWeeklyBuckets(\DateTimeImmutable $today, array $period): array
    {
        $type = strtolower((string) ($period['type'] ?? 'mtd'));
        if ($type === 'mtd') {
            $year  = (int) $today->format('Y');
            $month = (int) $today->format('m');
        } else {
            $year  = (int) ($period['year']  ?? $today->format('Y'));
            $month = (int) ($period['month'] ?? $today->format('m'));
            $month = max(1, min(12, $month));
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $monthEnd   = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        $buckets = [];
        for ($w = 1; $w <= 4; $w++) {
            $startDay = ($w - 1) * 7 + 1;
            $endDay   = $w * 7;
            $start = $monthStart->modify('+' . ($startDay - 1) . ' days');
            $end   = ($w === 4)
                ? $monthEnd
                : $monthStart->modify('+' . ($endDay - 1) . ' days')->setTime(23, 59, 59);
            $buckets[] = [
                'start' => $start,
                'end'   => $end,
                'label' => 'Week ' . $w,
            ];
        }
        return $buckets;
    }

    /**
     * Linear scan to find which bucket contains $dt. With 12 buckets max,
     * an O(N) scan is plenty — and keeps things robust against any
     * irregularly-shaped buckets we might add later.
     */
    private function findBucketIndex(\DateTimeImmutable $dt, array $buckets): ?int
    {
        foreach ($buckets as $i => $b) {
            if ($dt >= $b['start'] && $dt <= $b['end']) return $i;
        }
        return null;
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
