<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Direct-method cash flow analytics, sourced from the actual GL movements
 * on cash & bank accounts (codes 52* and 57*) — not P&L proxies.
 *
 * Methodology
 * ───────────
 * Opening balance:
 *   Σ balance of accounts where code starts with 52 or 57, on posted lines
 *   dated on or before the last day of the prior fiscal year (i.e. closing
 *   balance per the trial balance dated 31-Dec prior year).
 *
 * Closing balance:
 *   Σ balance of accounts 52* / 57* on posted lines dated on or before today.
 *
 * Collections (cash received from customers):
 *   For every posted move that contains BOTH a credit to a 41* account AND
 *   a debit to a 52* / 57* account in the YTD window, sum the debit amounts
 *   on the cash/bank lines. (i.e. the actual cash that flowed INTO the
 *   bank from settlement of receivables.)
 *
 * Categorised cash outflows:
 *   For each named expense category, sum the debit amounts on the expense
 *   account lines, but ONLY where the same posted move also contains a
 *   credit to a 52* / 57* account in the YTD window. (i.e. true cash
 *   payments — accruals booked against AP are excluded.)
 *
 *   Categories (account-code prefix → label):
 *     60*     → Cost of Goods Sold
 *     61*     → Transportation
 *     622*    → Rents
 *     6248*   → Maintenance & Repairs
 *     643400  → External Consultants
 *     66*     → Wages & Salaries
 *     632700  → Subcontractors
 *
 *   Longest-prefix match wins (so 6248* is captured before 62* would be).
 *
 * Other (balancing figure):
 *   Other = Opening + Collections − Σ(categorised outflows) − Closing
 *   Absorbs all uncategorised cash movement: loans, capex, dividends, bank
 *   fees, transfers, and any timing differences. Can be positive or negative.
 *
 * Cash evolution YTD:
 *   For each month-end from January through the current month, the cumulative
 *   balance of all 52* / 57* accounts as of that month-end (posted lines
 *   only). Plotted as a line chart.
 *
 * Journal balances (bank & cash):
 *   For each journal of type 'bank' or 'cash', the running balance of all
 *   posted move lines on that journal as of today. Reported as journal
 *   name + balance. Σ(journal balances) reconciles to the Closing balance.
 */
final class CashFlowService
{
    /** Account-code prefixes considered "cash & bank". */
    private const CASH_PREFIXES = ['52', '57'];

    /** Account-code prefix considered "trade receivables". */
    private const AR_PREFIXES = ['41'];

    /**
     * Outflow categories — order matters for display.
     * Longest prefix wins on overlap (handled in resolveCategoryAccounts()).
     */
    private const OUTFLOW_CATEGORIES = [
        ['key' => 'cogs',         'label' => 'Cost of Goods Sold',     'prefixes' => ['60']],
        ['key' => 'transport',    'label' => 'Transportation',         'prefixes' => ['61']],
        ['key' => 'rents',        'label' => 'Rents',                  'prefixes' => ['622']],
        ['key' => 'maintenance',  'label' => 'Maintenance & Repairs',  'prefixes' => ['6248']],
        ['key' => 'consultants',  'label' => 'External Consultants',   'prefixes' => ['643400']],
        ['key' => 'wages',        'label' => 'Wages & Salaries',       'prefixes' => ['66']],
        ['key' => 'subcontract',  'label' => 'Subcontractors',         'prefixes' => ['632700']],
    ];

    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① DIRECT CASH FLOW STATEMENT
    // ─────────────────────────────────────────────────────────────────────

    public function directCashFlow(?\DateTimeImmutable $today = null): array
    {
        $today        = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $jan1         = new \DateTimeImmutable($today->format('Y') . '-01-01');
        $priorYearEnd = $jan1->modify('-1 day');

        $cashAccountIds = $this->accountIdsByPrefix(self::CASH_PREFIXES);
        $arAccountIds   = $this->accountIdsByPrefix(self::AR_PREFIXES);

        // If no cash accounts exist in the chart, return an empty (but valid) result.
        if ($cashAccountIds === []) {
            return $this->emptyResult();
        }

        // Opening: closing balance per prior-year trial balance.
        $openingBalance = $this->sumBalanceAsOf($cashAccountIds, $priorYearEnd);
        // Closing: trial balance of cash accounts as of today.
        $closingBalance = $this->sumBalanceAsOf($cashAccountIds, $today);

        // Move IDs that touch cash in the YTD window — once, then reused.
        $cashReceiptMoveIds = $arAccountIds === []
            ? []
            : $this->moveIdsTouchingCash($cashAccountIds, $jan1, $today, debit: true);
        $cashPaymentMoveIds = $this->moveIdsTouchingCash($cashAccountIds, $jan1, $today, debit: false);

        // Collections: of the cash-receipt moves, keep only those that also credit AR;
        //              then sum the debit amounts on the cash/bank lines of those moves.
        $collections = 0.0;
        if ($cashReceiptMoveIds !== [] && $arAccountIds !== []) {
            $movesWithArCredit = $this->filterMovesWithSideOnAccounts(
                $cashReceiptMoveIds, $arAccountIds, side: 'credit'
            );
            if ($movesWithArCredit !== []) {
                $collections = $this->sumSideOnAccountsInMoves(
                    $cashAccountIds, $movesWithArCredit, side: 'debit'
                );
            }
        }

        // Categorised outflows: for each category, sum debits on the category's
        // accounts whose move also credits cash in the YTD window.
        $categorisedAccounts = $this->resolveCategoryAccounts();
        $outflows = [];
        $totalCategorised = 0.0;

        foreach (self::OUTFLOW_CATEGORIES as $cat) {
            $accountIds = $categorisedAccounts[$cat['key']] ?? [];
            $amount = 0.0;

            if ($accountIds !== [] && $cashPaymentMoveIds !== []) {
                $amount = $this->sumSideOnAccountsInMoves(
                    $accountIds, $cashPaymentMoveIds, side: 'debit'
                );
            }

            $outflows[] = [
                'key'    => $cat['key'],
                'label'  => $cat['label'],
                'amount' => round($amount, 2),
            ];
            $totalCategorised += $amount;
        }

        // Other = Opening + Collections − Σ(categorised) − Closing  (plug)
        $other = $openingBalance + $collections - $totalCategorised - $closingBalance;

        return [
            'currency'         => $this->detectCurrency(),
            'period'           => [
                'opening_as_of' => $priorYearEnd->format('Y-m-d'),
                'closing_as_of' => $today->format('Y-m-d'),
                'year'          => (int) $today->format('Y'),
            ],
            'opening_balance'  => round($openingBalance, 2),
            'collections'      => round($collections, 2),
            'outflows'         => $outflows,
            'total_outflows'   => round($totalCategorised, 2),
            'other'            => round($other, 2),
            'closing_balance'  => round($closingBalance, 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② CASH EVOLUTION YTD (line graph)
    // ─────────────────────────────────────────────────────────────────────

    public function cashEvolution(?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $year  = (int) $today->format('Y');
        $thisMonth = (int) $today->format('n');

        $cashAccountIds = $this->accountIdsByPrefix(self::CASH_PREFIXES);
        if ($cashAccountIds === []) return [];

        $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $points = [];

        for ($m = 1; $m <= $thisMonth; $m++) {
            // Last day of month m of current year, capped at today.
            $monthEnd = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $monthEnd = $monthEnd->modify('last day of this month');
            if ($monthEnd > $today) $monthEnd = $today;

            $bal = $this->sumBalanceAsOf($cashAccountIds, $monthEnd);
            $points[] = [
                'label'   => $monthLabels[$m - 1],
                'date'    => $monthEnd->format('Y-m-d'),
                'balance' => round($bal, 2),
            ];
        }

        return $points;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ JOURNAL BALANCES (bank & cash)
    // ─────────────────────────────────────────────────────────────────────

    public function journalBalances(?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        // Pull all bank/cash journals first so that journals with no movement
        // still show up (with a zero balance) in the table.
        $journals = $this->odoo->searchRead('account.journal',
            [['type', 'in', ['bank', 'cash']]],
            ['id', 'name', 'type', 'currency_id'],
            ['order' => 'sequence, name']
        );
        if ($journals === []) return [];

        $journalIds = array_map(fn ($j) => (int) $j['id'], $journals);

        // Aggregate balances grouped by journal in a single read_group call.
        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['journal_id', 'in', $journalIds],
                ['parent_state', '=', 'posted'],
                ['date', '<=', $today->format('Y-m-d')],
            ],
            'fields'  => ['journal_id', 'balance:sum'],
            'groupby' => ['journal_id'],
            'lazy'    => false,
        ]);

        $byJournal = [];
        foreach ($rows as $r) {
            if (!isset($r['journal_id']) || !is_array($r['journal_id'])) continue;
            $byJournal[(int) $r['journal_id'][0]] = (float) ($r['balance'] ?? 0);
        }

        $out = [];
        $total = 0.0;
        foreach ($journals as $j) {
            $bal = $byJournal[(int) $j['id']] ?? 0.0;
            $out[] = [
                'id'      => (int) $j['id'],
                'name'    => (string) $j['name'],
                'type'    => (string) ($j['type'] ?? ''),
                'balance' => round($bal, 2),
            ];
            $total += $bal;
        }

        return [
            'rows'  => $out,
            'total' => round($total, 2),
            'as_of' => $today->format('Y-m-d'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Find all account.account ids whose `code` starts with any given prefix.
     * Returns [] if none match.
     */
    private function accountIdsByPrefix(array $prefixes): array
    {
        if ($prefixes === []) return [];

        // Build an OR domain over all prefixes: [|, |, ['code','=like','60%'], ['code','=like','61%'], ...]
        $domain = [];
        $count  = count($prefixes);
        for ($i = 0; $i < $count - 1; $i++) {
            $domain[] = '|';
        }
        foreach ($prefixes as $p) {
            $domain[] = ['code', '=like', $p . '%'];
        }

        $rows = $this->odoo->searchRead('account.account', $domain, ['id', 'code'], []);
        return array_map(fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * For each outflow category, resolve the list of account ids whose code
     * matches the category's prefix(es). Account is assigned to the LONGEST
     * matching category prefix (so 6248* wins over 62*).
     */
    private function resolveCategoryAccounts(): array
    {
        // Pull all account ids/codes whose code starts with any digit 0-9
        // (cheap; the chart of accounts is small).
        $rows = $this->odoo->searchRead('account.account',
            [['code', '!=', false]],
            ['id', 'code'], []
        );

        // Sort prefixes by length desc — longer prefixes take precedence.
        $catsByLen = self::OUTFLOW_CATEGORIES;
        usort($catsByLen, function ($a, $b) {
            $maxA = max(array_map('strlen', $a['prefixes']));
            $maxB = max(array_map('strlen', $b['prefixes']));
            return $maxB <=> $maxA;
        });

        $assigned = [];
        foreach (self::OUTFLOW_CATEGORIES as $cat) {
            $assigned[$cat['key']] = [];
        }

        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '') continue;
            foreach ($catsByLen as $cat) {
                foreach ($cat['prefixes'] as $p) {
                    if (str_starts_with($code, $p)) {
                        $assigned[$cat['key']][] = (int) $r['id'];
                        continue 3; // stop scanning categories for this account
                    }
                }
            }
        }

        return $assigned;
    }

    /**
     * Σ posted move-line balance on `accountIds` dated on or before `asOf`.
     */
    private function sumBalanceAsOf(array $accountIds, \DateTimeImmutable $asOf): float
    {
        if ($accountIds === []) return 0.0;

        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['date', '<=', $asOf->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['account_id', 'balance:sum'],
            'groupby' => ['account_id'],
            'lazy'    => false,
        ]);

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r['balance'] ?? 0);
        }
        return $total;
    }

    /**
     * Distinct move ids that have at least one posted line on `cashAccountIds`
     * with a debit (debit=true) or credit (debit=false) > 0 in the YTD window.
     */
    private function moveIdsTouchingCash(
        array $cashAccountIds,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $debit
    ): array {
        if ($cashAccountIds === []) return [];

        $field = $debit ? 'debit' : 'credit';
        $lines = $this->odoo->searchRead('account.move.line', [
            ['account_id', 'in', $cashAccountIds],
            [$field, '>', 0],
            ['date', '>=', $from->format('Y-m-d')],
            ['date', '<=', $to->format('Y-m-d')],
            ['parent_state', '=', 'posted'],
        ], ['move_id'], []);

        $ids = [];
        foreach ($lines as $l) {
            $mv = $l['move_id'] ?? null;
            if (is_array($mv) && isset($mv[0])) $ids[(int) $mv[0]] = true;
        }
        return array_keys($ids);
    }

    /**
     * Of the given move ids, keep only those that contain at least one line on
     * `accountIds` with debit/credit > 0 (per `side`).
     */
    private function filterMovesWithSideOnAccounts(
        array $moveIds, array $accountIds, string $side
    ): array {
        if ($moveIds === [] || $accountIds === []) return [];

        $field = $side === 'debit' ? 'debit' : 'credit';
        $lines = $this->odoo->searchRead('account.move.line', [
            ['move_id', 'in', $moveIds],
            ['account_id', 'in', $accountIds],
            [$field, '>', 0],
        ], ['move_id'], []);

        $kept = [];
        foreach ($lines as $l) {
            $mv = $l['move_id'] ?? null;
            if (is_array($mv) && isset($mv[0])) $kept[(int) $mv[0]] = true;
        }
        return array_keys($kept);
    }

    /**
     * Σ debit (or credit) on lines where account_id ∈ accountIds AND
     * move_id ∈ moveIds. Server-aggregated via read_group.
     */
    private function sumSideOnAccountsInMoves(
        array $accountIds, array $moveIds, string $side
    ): float {
        if ($accountIds === [] || $moveIds === []) return 0.0;

        $field = $side === 'debit' ? 'debit' : 'credit';

        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['move_id', 'in', $moveIds],
                [$field, '>', 0],
            ],
            'fields'  => [$field . ':sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);

        return (float) ($rows[0][$field] ?? 0);
    }

    private function emptyResult(): array
    {
        $today = new \DateTimeImmutable('today');
        $year  = (int) $today->format('Y');
        $outflows = array_map(fn ($c) => [
            'key' => $c['key'], 'label' => $c['label'], 'amount' => 0.0,
        ], self::OUTFLOW_CATEGORIES);

        return [
            'currency'         => $this->detectCurrency(),
            'period'           => [
                'opening_as_of' => ($year - 1) . '-12-31',
                'closing_as_of' => $today->format('Y-m-d'),
                'year'          => $year,
            ],
            'opening_balance'  => 0.0,
            'collections'      => 0.0,
            'outflows'         => $outflows,
            'total_outflows'   => 0.0,
            'other'            => 0.0,
            'closing_balance'  => 0.0,
        ];
    }

    private function detectCurrency(): string
    {
        try {
            $user = $this->odoo->read('res.users', [$this->odoo->authenticate()], ['company_id']);
            $cid = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $co  = $this->odoo->read('res.company', [$cid], ['currency_id']);
            $crid = isset($co[0]['currency_id'][0]) ? (int) $co[0]['currency_id'][0] : 0;
            if ($crid > 0) {
                $c = $this->odoo->read('res.currency', [$crid], ['name']);
                if (isset($c[0]['name'])) return (string) $c[0]['name'];
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }
}
