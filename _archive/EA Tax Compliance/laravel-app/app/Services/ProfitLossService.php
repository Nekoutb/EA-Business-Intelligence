<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Profit & Loss KPIs computed directly from Odoo's general ledger.
 *
 * Methodology:
 *   Revenue    = -Σ balance on accounts of type 'income' | 'income_other'
 *   COGS       =  Σ balance on accounts of type 'expense_direct_cost'
 *   OpEx       =  Σ balance on accounts of type 'expense' (excluding D&A)
 *   D&A        =  Σ balance on accounts of type 'expense_depreciation'
 *   Gross Profit = Revenue - COGS
 *   EBITDA       = Revenue - COGS - OpEx
 *   EBIT         = EBITDA - D&A
 *   Net Profit   = Revenue - COGS - OpEx - D&A  (= -Σ balance all income/expense)
 *
 * Caveats:
 *   - Interest and tax expenses live in generic 'expense' accounts in the
 *     default Odoo chart of accounts; without additional tagging we cannot
 *     separate them from OpEx. EBIT therefore approximates EBT on charts
 *     where interest/tax are captured as regular expenses.
 *   - Uses Odoo 17+ `account_type` selection on account.account.
 */
final class ProfitLossService
{
    private const INCOME_TYPES  = ['income', 'income_other'];
    private const COGS_TYPES    = ['expense_direct_cost'];
    private const DA_TYPES      = ['expense_depreciation'];
    private const OPEX_TYPES    = ['expense']; // excludes direct cost + depreciation

    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today  = $today  ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);
        $pyFrom = $cyFrom->modify('-1 year');
        $pyTo   = $cyTo->modify('-1 year');

        // 1. Build account_id → account_type map (one small query).
        $allTypes = array_merge(self::INCOME_TYPES, self::COGS_TYPES, self::DA_TYPES, self::OPEX_TYPES);
        $accounts = $this->odoo->searchRead('account.account',
            [['account_type', 'in', $allTypes]],
            ['account_type'], []);
        $typeOf = [];
        foreach ($accounts as $a) {
            $typeOf[(int) $a['id']] = (string) $a['account_type'];
        }
        if ($typeOf === []) {
            return $this->emptyResult();
        }
        $accountIds = array_keys($typeOf);

        $cy = $this->fetchWindow($cyFrom, $cyTo, $accountIds, $typeOf);
        $py = $this->fetchWindow($pyFrom, $pyTo, $accountIds, $typeOf);

        return [
            'currency' => $this->detectCurrency(),
            'period'   => [
                'from'  => $cyFrom->format('Y-m-d'),
                'to'    => $cyTo->format('Y-m-d'),
                'label' => $periodLabel,
            ],
            'current'  => $cy,
            'previous' => $py,
            'delta'    => [
                'revenue_pct'      => $this->pct($cy['revenue'],       $py['revenue']),
                'gross_profit_pct' => $this->pct($cy['gross_profit'],  $py['gross_profit']),
                'ebitda_pct'       => $this->pct($cy['ebitda'],        $py['ebitda']),
                'ebit_pct'         => $this->pct($cy['ebit'],          $py['ebit']),
                'net_pct'          => $this->pct($cy['net_profit'],    $py['net_profit']),
                'gross_margin_pp'  => $cy['gross_margin_pct'] - $py['gross_margin_pct'],
                'ebitda_margin_pp' => $cy['ebitda_margin_pct'] - $py['ebitda_margin_pct'],
                'ebit_margin_pp'   => $cy['ebit_margin_pct']   - $py['ebit_margin_pct'],
                'net_margin_pp'    => $cy['net_margin_pct']    - $py['net_margin_pct'],
            ],
        ];
    }

    /**
     * Aggregate move-line balances for a window, grouped by account, then
     * by account_type category.
     */
    private function fetchWindow(\DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds, array $typeOf): array
    {
        $domain = [
            ['parent_state', '=', 'posted'],
            ['date', '>=', $from->format('Y-m-d')],
            ['date', '<=', $to->format('Y-m-d')],
            ['account_id', 'in', $accountIds],
        ];

        // read_group aggregates on the server so we avoid pulling every line.
        // Returns rows shaped like {account_id: [id, name], balance: sum, __count: n}.
        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain'  => $domain,
            'fields'  => ['account_id', 'balance:sum'],
            'groupby' => ['account_id'],
            'lazy'    => false,
        ]);

        $byType = [
            'income_balance'              => 0.0,
            'expense_direct_cost_balance' => 0.0,
            'expense_balance'             => 0.0,
            'expense_depreciation_balance'=> 0.0,
        ];
        foreach ($rows as $r) {
            if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
            $aid = (int) $r['account_id'][0];
            $bal = (float) ($r['balance'] ?? 0);
            $type = $typeOf[$aid] ?? null;
            if ($type === null) continue;
            if (in_array($type, self::INCOME_TYPES, true)) {
                $byType['income_balance'] += $bal;
            } elseif (in_array($type, self::COGS_TYPES, true)) {
                $byType['expense_direct_cost_balance'] += $bal;
            } elseif (in_array($type, self::DA_TYPES, true)) {
                $byType['expense_depreciation_balance'] += $bal;
            } elseif (in_array($type, self::OPEX_TYPES, true)) {
                $byType['expense_balance'] += $bal;
            }
        }

        // Balance sign convention: income accounts have credit-positive balance
        // (stored negative in Odoo's debit-positive "balance" field); expense
        // accounts have debit-positive balance.
        $revenue = -$byType['income_balance'];
        $cogs    =  $byType['expense_direct_cost_balance'];
        $opex    =  $byType['expense_balance'];
        $da      =  $byType['expense_depreciation_balance'];

        $grossProfit = $revenue - $cogs;
        $ebitda      = $revenue - $cogs - $opex;
        $ebit        = $ebitda - $da;
        $netProfit   = $ebit;   // no separate interest/tax bucket by default

        $gm = $revenue > 0 ? ($grossProfit / $revenue) * 100.0 : 0.0;
        $em = $revenue > 0 ? ($ebitda      / $revenue) * 100.0 : 0.0;
        $im = $revenue > 0 ? ($ebit        / $revenue) * 100.0 : 0.0;
        $nm = $revenue > 0 ? ($netProfit   / $revenue) * 100.0 : 0.0;

        return [
            'revenue'           => $revenue,
            'cogs'              => $cogs,
            'opex'              => $opex,
            'd_and_a'           => $da,
            'gross_profit'      => $grossProfit,
            'ebitda'            => $ebitda,
            'ebit'              => $ebit,
            'net_profit'        => $netProfit,
            'gross_margin_pct'  => $gm,
            'ebitda_margin_pct' => $em,
            'ebit_margin_pct'   => $im,
            'net_margin_pct'    => $nm,
        ];
    }

    private function emptyResult(): array
    {
        $zero = [
            'revenue' => 0.0, 'cogs' => 0.0, 'opex' => 0.0, 'd_and_a' => 0.0,
            'gross_profit' => 0.0, 'ebitda' => 0.0, 'ebit' => 0.0, 'net_profit' => 0.0,
            'gross_margin_pct' => 0.0, 'ebitda_margin_pct' => 0.0,
            'ebit_margin_pct' => 0.0, 'net_margin_pct' => 0.0,
        ];
        return [
            'currency' => 'USD',
            'period'   => ['from' => '', 'to' => ''],
            'current'  => $zero,
            'previous' => $zero,
            'delta'    => [
                'revenue_pct' => null, 'gross_profit_pct' => null,
                'ebitda_pct' => null, 'ebit_pct' => null, 'net_pct' => null,
                'gross_margin_pp' => 0.0, 'ebitda_margin_pp' => 0.0,
                'ebit_margin_pp' => 0.0, 'net_margin_pp' => 0.0,
            ],
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
        return 'USD';
    }

    private function pct(float $cur, float $prev): ?float
    {
        if ($prev == 0.0) return $cur == 0.0 ? 0.0 : null;
        return (($cur - $prev) / abs($prev)) * 100.0;
    }
}
