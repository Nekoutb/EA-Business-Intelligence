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
     * Match each class 70 account to a product in the registry using the
     * product's `revenue_match` patterns. Returns:
     *
     *   - 'matched'   — [product_key => [month => revenue]]
     *   - 'assigned'  — [account_id => product_key]  (for the diagnostic)
     *   - 'unmatched' — [account_id => true]          (accounts that didn't
     *                                                  land in any product)
     *
     * First-match-wins when a name could match multiple patterns. Empty-
     * container patterns are tested last by virtue of the registry order
     * — full-container excludes ['vide','empty'] keep them mutually
     * exclusive in practice.
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

        $matched   = [];   // product_key → [month => revenue]
        $assigned  = [];   // account_id  → product_key
        $unmatched = [];   // account_id  → true

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

        return [
            'matched'   => $matched,
            'assigned'  => $assigned,
            'unmatched' => $unmatched,
        ];
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
