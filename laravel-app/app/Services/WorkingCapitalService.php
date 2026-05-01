<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Working Capital analytics — eight ratios computed directly from the
 * SYSCOHADA general ledger.
 *
 * Ratios produced:
 *   Current Ratio        = Current Assets ÷ Current Liabilities
 *   Quick Ratio          = (Current Assets − Inventory) ÷ Current Liabilities
 *   Cash Ratio           = Cash & Equivalents ÷ Current Liabilities
 *   Working Capital      = Current Assets − Current Liabilities      (XAF)
 *   DSO                  = (AR ÷ Revenue_TTM) × 365                  (days)
 *   DPO                  = (AP ÷ COGS_TTM)    × 365                  (days)
 *   DIO                  = (Inventory ÷ COGS_TTM) × 365              (days)
 *   Cash Conversion Cycle = DSO + DIO − DPO                          (days)
 *
 * Methodology — code-prefix based, version-agnostic across Odoo 14..19+
 * (matches ProfitLossService approach):
 *
 *   Class 3 (3*)         → Inventory (debit balances)
 *   Class 41 (41*)       → AR — debit balances are AR; credit balances
 *                          are customer advances and roll into current
 *                          liabilities
 *   Class 40 (40*)       → AP — credit balances are AP; debit balances
 *                          are supplier advances (current asset)
 *   Class 5 (5*)         → Cash & equivalents — debit balances are
 *                          cash/banks; credit balances are bank
 *                          overdrafts (current liability)
 *   Class 4 other (42-49)→ Split by sign — debit = current asset,
 *                          credit = current liability
 *
 *   Revenue_TTM          = -Σ class-7 balance, trailing 12 months
 *                          ending at as_of (excludes 79 — provisions)
 *   COGS_TTM             =  Σ class-60 balance, trailing 12 months
 *                          ending at as_of
 *
 * Sign convention reminder: in Odoo move_line.balance is debit-positive.
 * Revenue (class 7, credit-normal) shows as negative balance — flip the
 * sign for the magnitude. Same for AP (class 40 credit-normal).
 *
 * Why TTM (not period)?
 *   DSO/DPO/DIO are by definition annualised on a 365-day basis. Using
 *   the period's revenue/COGS would distort the days figures (e.g. an
 *   MTD denominator with a balance-sheet snapshot would scale the days
 *   by 30:365 ≈ 12×). TTM keeps the annualisation honest regardless of
 *   the as-of date selected.
 */
final class WorkingCapitalService
{
    /** Inventory (SYSCOHADA Class 3). */
    private const INVENTORY_PREFIXES = ['3'];

    /** AR (Class 41 — clients). */
    private const AR_PREFIXES = ['41'];

    /** AP (Class 40 — fournisseurs). */
    private const AP_PREFIXES = ['40'];

    /** Cash & banks (Class 5 — comptes financiers). */
    private const CASH_PREFIXES = ['5'];

    /** All current-tiers accounts (Class 4 other than AR/AP). */
    private const CURRENT_TIERS_PREFIXES = ['42', '43', '44', '45', '46', '47', '48', '49'];

    /** Revenue (Class 7 except 79). */
    private const REVENUE_PREFIXES         = ['7'];
    private const REVENUE_EXCLUDE_PREFIXES = ['79'];

    /** COGS / direct cost (Class 60 — Achats). */
    private const COGS_PREFIXES = ['60'];

    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $asOf = null): array
    {
        $asOf = $asOf ?? new \DateTimeImmutable('today');

        $balances = $this->fetchBalanceSheet($asOf);
        $ttm      = $this->fetchTtm($asOf);

        $ar        = $balances['ar'];
        $ap        = $balances['ap'];
        $cash      = $balances['cash'];
        $inventory = $balances['inventory'];
        $ca        = $balances['current_assets'];
        $cl        = $balances['current_liabilities'];

        $revenue   = $ttm['revenue'];
        $cogs      = $ttm['cogs'];

        // Liquidity ratios
        $currentRatio = $cl > 0 ? $ca / $cl : null;
        $quickRatio   = $cl > 0 ? ($ca - $inventory) / $cl : null;
        $cashRatio    = $cl > 0 ? $cash / $cl : null;
        $workingCap   = $ca - $cl;

        // Cycle ratios
        $dso = $revenue > 0 ? ($ar / $revenue) * 365.0 : null;
        $dpo = $cogs    > 0 ? ($ap / $cogs)    * 365.0 : null;
        $dio = $cogs    > 0 ? ($inventory / $cogs) * 365.0 : null;
        $ccc = ($dso !== null && $dpo !== null && $dio !== null)
            ? $dso + $dio - $dpo
            : null;

        return [
            'currency' => $this->detectCurrency(),
            'as_of'    => $asOf->format('Y-m-d'),
            'ttm_window' => [
                'from' => $asOf->modify('-1 year +1 day')->format('Y-m-d'),
                'to'   => $asOf->format('Y-m-d'),
            ],

            // Headline ratios
            'ratios' => [
                'current_ratio'   => $currentRatio,
                'quick_ratio'     => $quickRatio,
                'cash_ratio'      => $cashRatio,
                'working_capital' => $workingCap,
                'dso'             => $dso,
                'dpo'             => $dpo,
                'dio'             => $dio,
                'ccc'             => $ccc,
            ],

            // Component balances (so the page can show how the ratios decompose)
            'components' => [
                'current_assets'      => $ca,
                'current_liabilities' => $cl,
                'cash_and_equivalents' => $cash,
                'accounts_receivable' => $ar,
                'inventory'           => $inventory,
                'accounts_payable'    => $ap,
                'revenue_ttm'         => $revenue,
                'cogs_ttm'            => $cogs,
            ],
        ];
    }

    /**
     * Pull balances for every balance-sheet account (classes 3, 4, 5) as of
     * the requested date and bucket them into AR / AP / Cash / Inventory /
     * other current.
     *
     * @return array{
     *   ar:float, ap:float, cash:float, inventory:float,
     *   current_assets:float, current_liabilities:float
     * }
     */
    private function fetchBalanceSheet(\DateTimeImmutable $asOf): array
    {
        $result = [
            'ar' => 0.0, 'ap' => 0.0, 'cash' => 0.0, 'inventory' => 0.0,
            'current_assets' => 0.0, 'current_liabilities' => 0.0,
        ];

        try {
            // 1) Pull the chart of accounts for classes 3, 4, 5 (every code
            // that begins with one of those digits). Single Odoo call.
            $accounts = $this->odoo->searchRead('account.account',
                [
                    '|', '|',
                    ['code', '=like', '3%'],
                    ['code', '=like', '4%'],
                    ['code', '=like', '5%'],
                ],
                ['id', 'code'],
                []
            );
            if (empty($accounts)) return $result;

            $codeByAccount = [];
            $accountIds    = [];
            foreach ($accounts as $a) {
                $aid  = (int)    $a['id'];
                $code = (string) ($a['code'] ?? '');
                if ($code === '') continue;
                $codeByAccount[$aid] = $code;
                $accountIds[] = $aid;
            }
            if (empty($accountIds)) return $result;

            // 2) Aggregate posted move-line balances per account, all dates
            // up to and including the as-of date. read_group keeps the
            // payload tiny.
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '<=', $asOf->format('Y-m-d')],
                    ['account_id', 'in', $accountIds],
                ],
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            // 3) Bucket by code prefix.
            foreach ($rows as $r) {
                if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
                $aid  = (int)   $r['account_id'][0];
                $bal  = (float) ($r['balance'] ?? 0);
                $code = $codeByAccount[$aid] ?? '';
                if ($code === '') continue;

                // Class 3 — Inventory (debit-normal)
                if ($this->codeStartsWithAny($code, self::INVENTORY_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['inventory']      += $bal;
                        $result['current_assets'] += $bal;
                    } else {
                        // Credit balance on inventory account is unusual but
                        // possible (returns / consignment) — treat as
                        // current liability so we don't double-count.
                        $result['current_liabilities'] += -$bal;
                    }
                    continue;
                }

                // Class 41 — AR (debit-normal)
                if ($this->codeStartsWithAny($code, self::AR_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['ar']             += $bal;
                        $result['current_assets'] += $bal;
                    } else {
                        // Credit balance on AR = customer advance / deposit.
                        $result['current_liabilities'] += -$bal;
                    }
                    continue;
                }

                // Class 40 — AP (credit-normal → balance is negative when
                // money is owed to suppliers)
                if ($this->codeStartsWithAny($code, self::AP_PREFIXES)) {
                    if ($bal <= 0) {
                        $result['ap']                  += -$bal;
                        $result['current_liabilities'] += -$bal;
                    } else {
                        // Debit balance on AP = supplier advance / prepayment.
                        $result['current_assets'] += $bal;
                    }
                    continue;
                }

                // Class 5 — Cash & banks (debit-normal)
                if ($this->codeStartsWithAny($code, self::CASH_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['cash']           += $bal;
                        $result['current_assets'] += $bal;
                    } else {
                        // Negative cash = bank overdraft (découvert).
                        $result['current_liabilities'] += -$bal;
                    }
                    continue;
                }

                // Class 4 other (42-49) — split by sign
                if ($this->codeStartsWithAny($code, self::CURRENT_TIERS_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['current_assets'] += $bal;
                    } else {
                        $result['current_liabilities'] += -$bal;
                    }
                }
            }
        } catch (\Throwable $e) {
            // GL not reachable — return zeros; the controller will surface
            // an error banner via the empty-state check.
        }

        return $result;
    }

    /**
     * Trailing-12-month revenue and COGS ending at $asOf.
     *
     * @return array{revenue:float, cogs:float}
     */
    private function fetchTtm(\DateTimeImmutable $asOf): array
    {
        $from = $asOf->modify('-1 year +1 day');
        $result = ['revenue' => 0.0, 'cogs' => 0.0];

        try {
            $accounts = $this->odoo->searchRead('account.account',
                [
                    '|',
                    ['code', '=like', '6%'],
                    ['code', '=like', '7%'],
                ],
                ['id', 'code'],
                []
            );
            if (empty($accounts)) return $result;

            $bucketOf = [];
            $accountIds = [];
            foreach ($accounts as $a) {
                $aid  = (int)    $a['id'];
                $code = (string) ($a['code'] ?? '');
                if ($code === '') continue;

                if ($this->codeStartsWithAny($code, self::COGS_PREFIXES)) {
                    $bucketOf[$aid] = 'cogs';
                    $accountIds[] = $aid;
                } elseif ($this->codeStartsWithAny($code, self::REVENUE_PREFIXES)
                       && !$this->codeStartsWithAny($code, self::REVENUE_EXCLUDE_PREFIXES)) {
                    $bucketOf[$aid] = 'revenue';
                    $accountIds[] = $aid;
                }
            }
            if (empty($accountIds)) return $result;

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $from->format('Y-m-d')],
                    ['date', '<=', $asOf->format('Y-m-d')],
                    ['account_id', 'in', $accountIds],
                ],
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            $rev = 0.0; $cogs = 0.0;
            foreach ($rows as $r) {
                if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
                $aid    = (int)   $r['account_id'][0];
                $bal    = (float) ($r['balance'] ?? 0);
                $bucket = $bucketOf[$aid] ?? null;
                if ($bucket === 'revenue') $rev  += $bal;
                if ($bucket === 'cogs')    $cogs += $bal;
            }

            // Revenue is credit-positive → flip to magnitude.
            $result['revenue'] = -$rev;
            $result['cogs']    =  $cogs;
        } catch (\Throwable $e) {
            // P&L not reachable — leave at zero.
        }

        return $result;
    }

    private function codeStartsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($code, $p)) return true;
        }
        return false;
    }

    private function detectCurrency(): string
    {
        try {
            $user = $this->odoo->read('res.users', [$this->odoo->authenticate()], ['company_id']);
            $cid  = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $co   = $this->odoo->read('res.company', [$cid], ['currency_id']);
            $crid = isset($co[0]['currency_id'][0]) ? (int) $co[0]['currency_id'][0] : 0;
            if ($crid > 0) {
                $c = $this->odoo->read('res.currency', [$crid], ['name']);
                if (isset($c[0]['name'])) return (string) $c[0]['name'];
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }
}
