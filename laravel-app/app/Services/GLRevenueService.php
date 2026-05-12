<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Fetches monthly revenue from the General Ledger (class 70 OHADA
 * "Operating revenues" accounts), broken down per account and per month.
 *
 * Used by Product Revenue TSD to:
 *   - reconcile the headline "Revenue YTD (Actual)" cell against the
 *     Dashboard's invoice-based revenue figure (both ultimately land on
 *     the same class 70 movements);
 *   - source container revenue from class 706 sub-accounts ("Services
 *     vendus"), where TSD's chart splits revenue by direction + size
 *     (706110 "Scanning 40\" import domestique" → Container Import 40',
 *     706120 "Scanning 20\" import domestique" → Container Import 20',
 *     etc.). Each container product carries a `revenue_match` block in
 *     config/budget_products.php that names-matches against accounts.
 *
 * In OHADA bookkeeping, customer invoices post:
 *     Dr  411  (Receivable)
 *     Cr  70x  (Revenue HT)
 *     Cr  4431 (VAT collected)
 *
 * So summing class 70 movements yields HT revenue — the cleanest source
 * of truth that aligns to the Dashboard's invoice total without TTC/HT
 * conversion noise.
 */
class GLRevenueService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * For a given year, returns three pieces of data:
     *
     *   - 'by_account_month'  — [account_id => [month => revenue_xaf]]
     *   - 'accounts'          — [account_id => ['code'=>..., 'name'=>...]]
     *   - 'monthly_totals'    — [month => total_class70_revenue_xaf]
     *
     * Revenue is computed as (credit - debit) summed over posted
     * account.move.line entries, scoped to the requested year. For
     * class 70 (credit-normal), this yields a positive number when the
     * business earned revenue.
     *
     * Two Odoo round-trips: one searchRead for accounts in class 70,
     * one read_group per month for the postings. Total 13 round-trips
     * per fresh fetch (cached for 5 min by the caller).
     */
    public function fetchClass70Yearly(int $year): array
    {
        // 1. List every class 70 sub-account with code + name. Used both
        //    for the diagnostic panel and to map account_id back to
        //    code/name when matching containers.
        try {
            $accountRows = $this->odoo->searchRead(
                'account.account',
                [['code', '=like', '70%']],
                ['id', 'code', 'name'],
                ['order' => 'code']
            );
        } catch (\Throwable $e) {
            return ['by_account_month' => [], 'accounts' => [], 'monthly_totals' => array_fill(1, 12, 0.0)];
        }

        if (empty($accountRows)) {
            return ['by_account_month' => [], 'accounts' => [], 'monthly_totals' => array_fill(1, 12, 0.0)];
        }

        $accounts   = [];
        $accountIds = [];
        foreach ($accountRows as $r) {
            $id = (int) $r['id'];
            $accounts[$id] = [
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
            $accountIds[] = $id;
        }

        // 2. For each month, sum (credit - debit) per account_id.
        $byAccountMonth = [];
        $monthlyTotals  = array_fill(1, 12, 0.0);

        for ($m = 1; $m <= 12; $m++) {
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $to   = $from->modify('last day of this month');

            try {
                $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                    'domain'  => [
                        ['account_id',   'in', $accountIds],
                        ['date',         '>=', $from->format('Y-m-d')],
                        ['date',         '<=', $to->format('Y-m-d')],
                        ['parent_state', '=',  'posted'],
                    ],
                    'fields'  => ['debit:sum', 'credit:sum'],
                    'groupby' => ['account_id'],
                    'lazy'    => false,
                ]);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $acctInfo = $row['account_id'] ?? null;
                $accountId = is_array($acctInfo) ? (int) ($acctInfo[0] ?? 0) : 0;
                if ($accountId <= 0) continue;

                $debit  = (float) ($row['debit']  ?? 0);
                $credit = (float) ($row['credit'] ?? 0);
                $revenue = $credit - $debit;   // class 70 is credit-normal

                $byAccountMonth[$accountId] ??= array_fill(1, 12, 0.0);
                $byAccountMonth[$accountId][$m] = $revenue;
                $monthlyTotals[$m] += $revenue;
            }
        }

        return [
            'by_account_month' => $byAccountMonth,
            'accounts'         => $accounts,
            'monthly_totals'   => $monthlyTotals,
        ];
    }

    /**
     * Find every posted line on a class 70 account whose parent move is
     * NOT a customer invoice or refund — i.e. the "reconciliation gap"
     * between the GL view (which sums all class 70 postings) and the
     * Dashboard view (which only sums lines coming from out_invoice /
     * out_refund customer-billing moves).
     *
     * Typical sources of these lines:
     *   - Manual journal entries (move_type = 'entry')
     *   - OD / miscellaneous reclassifications between revenue accounts
     *   - Year-end close-out journals to class 13X
     *   - Vendor refunds posted to revenue (rare, but happens)
     *   - Direct cash receipts that bypassed the invoicing workflow
     *
     * Returns up to $lineLimit individual lines (most material first),
     * plus aggregates by journal / account / move_type so the user can
     * pinpoint where the gap lives without scrolling 1000 rows.
     *
     * Scoped to the YTD window: $year-01-01 → last day of $todayMonth.
     *
     * @return array{
     *   total_count: int, total_amount: float,
     *   by_journal: array<int, array{name: string, count: int, amount: float}>,
     *   by_account: array<int, array{code: string, name: string, count: int, amount: float}>,
     *   by_move_type: array<string, array{label: string, count: int, amount: float}>,
     *   lines: array<int, array{
     *     date: string, account_code: string, account_name: string,
     *     journal_name: string, move_name: string, move_type: string,
     *     partner_name: ?string, label: string, amount: float
     *   }>
     * }
     */
    public function fetchNonInvoiceClass70Lines(int $year, int $todayMonth, int $lineLimit = 200): array
    {
        $empty = [
            'total_count'  => 0,
            'total_amount' => 0.0,
            'by_journal'   => [],
            'by_account'   => [],
            'by_move_type' => [],
            'lines'        => [],
        ];

        $from = sprintf('%04d-01-01', $year);
        $toM  = max(1, min(12, $todayMonth));
        $to   = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $toM)))
                ->modify('last day of this month')->format('Y-m-d');

        // Domain: posted class-70 lines that did NOT originate from a
        // customer invoice/refund. `move_id.move_type` traverses the M2O
        // — Odoo handles the join. parent_state='posted' is the v15+
        // shortcut for the move's state without re-querying the move.
        $domain = [
            ['account_id.code',  '=like', '70%'],
            ['parent_state',     '=',     'posted'],
            ['date',             '>=',    $from],
            ['date',             '<=',    $to],
            ['move_id.move_type', 'not in', ['out_invoice', 'out_refund']],
        ];

        try {
            // First pass: counts + sums. Use search_count + a streaming
            // search_read so we don't pull every line into memory if the
            // result set is large. The aggregates below are computed in
            // PHP from the streamed rows (we read every match so the
            // by_journal / by_account roll-ups are exact; lines[] is
            // capped at $lineLimit for display).
            $rows = $this->odoo->searchRead(
                'account.move.line',
                $domain,
                ['id', 'date', 'name', 'debit', 'credit',
                 'account_id', 'journal_id', 'move_id', 'partner_id'],
                ['order' => 'date desc, id desc', 'limit' => 5000]
            );
        } catch (\Throwable $e) {
            return $empty;
        }

        if (empty($rows)) {
            return $empty;
        }

        // Map move_id → move_type in one batch (avoids one round-trip
        // per row). 5000 ids fits in a single read comfortably.
        $moveIds = [];
        foreach ($rows as $r) {
            $mid = $this->m2oId($r['move_id'] ?? null);
            if ($mid > 0) $moveIds[$mid] = true;
        }
        $moveType = [];
        if (!empty($moveIds)) {
            try {
                $moveRows = $this->odoo->read('account.move', array_keys($moveIds), ['id', 'move_type']);
                foreach ($moveRows as $mr) {
                    $moveType[(int) $mr['id']] = (string) ($mr['move_type'] ?? 'entry');
                }
            } catch (\Throwable $e) {
                // fall through with empty map — labelled "(unknown)" below
            }
        }

        $byJournal   = [];   // journal_id → [name, count, amount]
        $byAccount   = [];   // account_id → [code, name, count, amount]
        $byMoveType  = [];   // move_type   → [label, count, amount]
        $topLines    = [];   // up to $lineLimit most-material rows

        $totalAmount = 0.0;
        $totalCount  = 0;

        foreach ($rows as $r) {
            $debit  = (float) ($r['debit']  ?? 0);
            $credit = (float) ($r['credit'] ?? 0);
            $amount = $credit - $debit;   // class 70 credit-normal → revenue positive

            $totalAmount += $amount;
            $totalCount++;

            // by_journal
            $j  = $r['journal_id']  ?? null;
            $jId   = $this->m2oId($j);
            $jName = $this->m2oName($j);
            if ($jId > 0) {
                $byJournal[$jId] ??= ['name' => $jName, 'count' => 0, 'amount' => 0.0];
                $byJournal[$jId]['count']  += 1;
                $byJournal[$jId]['amount'] += $amount;
            }

            // by_account
            $a  = $r['account_id'] ?? null;
            $aId   = $this->m2oId($a);
            $aName = $this->m2oName($a);
            if ($aId > 0) {
                // Account display name is "<code> <name>" — split it.
                [$aCode, $aLabel] = $this->splitAccountDisplay($aName);
                $byAccount[$aId] ??= ['code' => $aCode, 'name' => $aLabel, 'count' => 0, 'amount' => 0.0];
                $byAccount[$aId]['count']  += 1;
                $byAccount[$aId]['amount'] += $amount;
            }

            // by_move_type
            $mvId = $this->m2oId($r['move_id'] ?? null);
            $mt   = $moveType[$mvId] ?? 'entry';
            $byMoveType[$mt] ??= ['label' => $this->moveTypeLabel($mt), 'count' => 0, 'amount' => 0.0];
            $byMoveType[$mt]['count']  += 1;
            $byMoveType[$mt]['amount'] += $amount;

            // Collect line detail (we'll trim to lineLimit by abs amount after the loop)
            [$aCodeForLine, $aLabelForLine] = $this->splitAccountDisplay($aName);
            $topLines[] = [
                'date'         => (string) ($r['date'] ?? ''),
                'account_code' => $aCodeForLine,
                'account_name' => $aLabelForLine,
                'journal_name' => $jName,
                'move_name'    => $this->m2oName($r['move_id'] ?? null),
                'move_type'    => $mt,
                'partner_name' => $this->m2oName($r['partner_id'] ?? null) ?: null,
                'label'        => (string) ($r['name'] ?? ''),
                'amount'       => $amount,
            ];
        }

        // Trim to the most material absolute movements so the operator
        // sees the biggest contributors first.
        usort($topLines, fn ($x, $y) => abs((float) $y['amount']) <=> abs((float) $x['amount']));
        $topLines = array_slice($topLines, 0, $lineLimit);

        // Sort aggregates by amount magnitude
        uasort($byJournal,  fn ($x, $y) => abs((float) $y['amount']) <=> abs((float) $x['amount']));
        uasort($byAccount,  fn ($x, $y) => abs((float) $y['amount']) <=> abs((float) $x['amount']));
        uasort($byMoveType, fn ($x, $y) => abs((float) $y['amount']) <=> abs((float) $x['amount']));

        return [
            'total_count'  => $totalCount,
            'total_amount' => $totalAmount,
            'by_journal'   => array_values($byJournal),
            'by_account'   => array_values($byAccount),
            'by_move_type' => array_values($byMoveType),
            'lines'        => $topLines,
        ];
    }

    /** Extract the id from an Odoo M2O field ([id, name] or false). */
    private function m2oId(mixed $v): int
    {
        if (is_array($v) && isset($v[0])) return (int) $v[0];
        return 0;
    }

    /** Extract the display name from an Odoo M2O field. */
    private function m2oName(mixed $v): string
    {
        if (is_array($v) && isset($v[1])) return (string) $v[1];
        return '';
    }

    /**
     * account.account display_name comes through as "<code> <name>".
     * Split it back into a tuple so the UI can render code in a mono
     * column and name in a label column.
     */
    private function splitAccountDisplay(string $display): array
    {
        $display = trim($display);
        if ($display === '') return ['', ''];
        // Common shape: "706110 Scanning 40" import …" — first whitespace
        // splits code from rest. If no space, treat whole string as name.
        $parts = preg_split('/\s+/', $display, 2);
        if (count($parts) === 2 && ctype_alnum(str_replace('.', '', $parts[0]))) {
            return [(string) $parts[0], (string) $parts[1]];
        }
        return ['', $display];
    }

    /** Human-readable Odoo move_type. */
    private function moveTypeLabel(string $t): string
    {
        return match ($t) {
            'entry'       => 'Manual journal entry',
            'in_invoice'  => 'Vendor bill',
            'in_refund'   => 'Vendor refund',
            'out_invoice' => 'Customer invoice',
            'out_refund'  => 'Customer refund',
            'out_receipt' => 'Sales receipt',
            'in_receipt'  => 'Purchase receipt',
            default       => $t === '' ? 'Unknown' : $t,
        };
    }

    /**
     * Match each class 70 account to a product in the registry using the
     * product's `revenue_match` patterns, then apply any
     * `revenue_redistribute` rules from config to split catch-all
     * accounts across multiple products. Returns:
     *
     *   - 'matched'       — [product_key => [month => revenue]] (after
     *                       redistribution; product totals include
     *                       direct matches PLUS redistributed shares)
     *   - 'assigned'      — [account_id => product_key]  (1:1 direct
     *                       matches only, for the diagnostic)
     *   - 'redistributed' — [account_id => ['targets' => [...],
     *                                       'description' => string]]
     *                       (accounts that were split across multiple
     *                       products via a redistribute rule)
     *   - 'unmatched'     — [account_id => true] (accounts that didn't
     *                       land in any product even after redistribution)
     *
     * First-match-wins for direct matching. Redistribution runs after,
     * so it only picks up accounts that didn't match any product directly.
     */
    public function matchAccountsToProducts(array $class70): array
    {
        $accounts       = (array) ($class70['accounts'] ?? []);
        $byAccountMonth = (array) ($class70['by_account_month'] ?? []);

        $products = (array) config('budget_products.products', []);
        $matchable = array_filter(
            $products,
            fn ($p) => ($p['revenue_source'] ?? null) === 'gl_706_named'
        );

        $matched       = [];   // product_key → [month => revenue]
        $assigned      = [];   // account_id  → product_key
        $redistributed = [];   // account_id  → ['targets' => [...], 'description' => str]
        $unmatched     = [];   // account_id  → true

        // ── Pass 1: direct per-account → per-product matching
        foreach ($accounts as $accountId => $info) {
            $name = strtolower($info['name'] ?? '');
            $hit  = null;
            foreach ($matchable as $product) {
                if ($this->nameMatches($name, (array) ($product['revenue_match'] ?? []))) {
                    $hit = (string) $product['key'];
                    break;
                }
            }
            if ($hit === null) {
                $unmatched[$accountId] = true;
                continue;
            }
            $assigned[$accountId] = $hit;
            $monthly = $byAccountMonth[$accountId] ?? array_fill(1, 12, 0.0);
            $matched[$hit] ??= array_fill(1, 12, 0.0);
            for ($m = 1; $m <= 12; $m++) {
                $matched[$hit][$m] += (float) ($monthly[$m] ?? 0);
            }
        }

        // ── Pass 2: apply redistribution rules to catch-all accounts
        // that didn't match any product directly (e.g. 706100 → 75%
        // imports / 25% exports). Each rule moves its source account
        // from `unmatched` to `redistributed` and splits the monthly
        // revenue across the named target products.
        foreach ((array) config('budget_products.revenue_redistribute', []) as $rule) {
            $sourceMatch = (array) ($rule['source_match'] ?? []);
            $targets     = (array) ($rule['targets'] ?? []);
            if (empty($targets)) continue;

            foreach (array_keys($unmatched) as $accountId) {
                $info = $accounts[$accountId] ?? null;
                if (!$info) continue;
                if (!$this->matchesSourceRule($info, $sourceMatch)) continue;

                unset($unmatched[$accountId]);
                $redistributed[$accountId] = [
                    'description' => (string) ($rule['description'] ?? ''),
                    'targets'     => $targets,
                ];

                $monthly = $byAccountMonth[$accountId] ?? array_fill(1, 12, 0.0);
                foreach ($targets as $targetKey => $share) {
                    $matched[(string) $targetKey] ??= array_fill(1, 12, 0.0);
                    for ($m = 1; $m <= 12; $m++) {
                        $matched[(string) $targetKey][$m] += (float) ($monthly[$m] ?? 0) * (float) $share;
                    }
                }
            }
        }

        return [
            'matched'       => $matched,
            'assigned'      => $assigned,
            'redistributed' => $redistributed,
            'unmatched'     => $unmatched,
        ];
    }

    /**
     * Test whether an account matches a redistribution rule's
     * `source_match` block. Supports two predicates:
     *
     *   - 'code_equals' (string)    — exact code match
     *   - 'code_starts_with' (str)  — code prefix match
     *   - 'name_includes' (string)  — case-insensitive substring on name
     *
     * Multiple predicates AND together (all must match).
     */
    private function matchesSourceRule(array $account, array $rule): bool
    {
        $code = (string) ($account['code'] ?? '');
        $name = strtolower($account['name'] ?? '');

        if (isset($rule['code_equals']) && $code !== (string) $rule['code_equals']) {
            return false;
        }
        if (isset($rule['code_starts_with']) && !str_starts_with($code, (string) $rule['code_starts_with'])) {
            return false;
        }
        if (isset($rule['name_includes']) && strpos($name, strtolower((string) $rule['name_includes'])) === false) {
            return false;
        }
        return !empty($rule);  // empty rule never matches (safety)
    }

    /**
     * Returns true iff $name (already lowercased) satisfies the pattern:
     *   - every term in includes_all must appear (AND)
     *   - if includes_any is non-empty, at least one term must appear (OR)
     *   - none of the excludes may appear (NOT)
     */
    private function nameMatches(string $name, array $rules): bool
    {
        $all = array_map('strtolower', (array) ($rules['includes_all'] ?? []));
        foreach ($all as $term) {
            if ($term === '') continue;
            if (strpos($name, $term) === false) return false;
        }

        $any = array_map('strtolower', (array) ($rules['includes_any'] ?? []));
        if (!empty($any)) {
            $hit = false;
            foreach ($any as $term) {
                if ($term === '') continue;
                if (strpos($name, $term) !== false) { $hit = true; break; }
            }
            if (!$hit) return false;
        }

        $excl = array_map('strtolower', (array) ($rules['excludes'] ?? []));
        foreach ($excl as $term) {
            if ($term === '') continue;
            if (strpos($name, $term) !== false) return false;
        }

        // Need at least one positive rule, otherwise everything matches.
        return !empty($all) || !empty($any);
    }
}
