<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Profit & Loss KPIs computed directly from Odoo's general ledger.
 *
 * Methodology (SYSCOHADA / OHADA chart of accounts — version-agnostic):
 *   Revenue    = -Σ balance on accounts whose code starts with 7 (excl. 79
 *                — provision reversals are not operating revenue)
 *   COGS       =  Σ balance on accounts whose code starts with 60
 *                (Achats: direct costs / cost of sales)
 *   D&A        =  Σ balance on accounts whose code starts with 68
 *                (Dotations aux amortissements et provisions)
 *   OpEx       =  Σ balance on class-6 accounts NOT in the COGS or D&A
 *                buckets, AND excluding 69 (HAO / income-tax-related)
 *
 *   Gross Profit = Revenue - COGS
 *   EBITDA       = Revenue - COGS - OpEx
 *   EBIT         = EBITDA - D&A
 *   Net Profit   = EBIT  (no separate interest/tax bucket here; income tax
 *                         lives in account 89 and is surfaced separately
 *                         on the Tax Dashboard)
 *
 * Why code prefixes (not Odoo's account_type)?
 *   `account_type` on `account.account` was introduced in Odoo 17. On
 *   Odoo 14-16 the equivalent is `user_type_id` (a Many2one to a separate
 *   model), which differs in shape. Filtering by `code` works identically
 *   on every Odoo version (14 → 19+) and matches the OHADA-canonical
 *   classification more precisely than Odoo's generic categories anyway.
 *
 * Caveats:
 *   - Interest charges live in 67. They're classified here as OpEx, so
 *     EBIT approximates pre-tax pre-interest profit. If a client wants
 *     interest broken out, that's a small addition (separate '67' bucket).
 *   - Income tax expense (account 89) is intentionally excluded from
 *     OpEx — it's reported on the Tax Dashboard.
 */
final class ProfitLossService
{
    /** Accounts whose code starts with these are revenue (class 7 except 79). */
    private const REVENUE_PREFIXES        = ['7'];
    private const REVENUE_EXCLUDE_PREFIXES = ['79'];

    /** Direct cost / Cost of Sales — SYSCOHADA Class 60 (Achats). */
    private const COGS_PREFIXES = ['60'];

    /** Depreciation & Amortization — SYSCOHADA Class 68. */
    private const DA_PREFIXES = ['68'];

    /**
     * Operating expense = class 6 EXCEPT direct costs (60), D&A (68),
     * HAO/income tax-related (69). Other class-6 sub-classes (61..67)
     * fall through here.
     */
    private const OPEX_PREFIXES = ['6'];
    private const OPEX_EXCLUDE_PREFIXES = ['60', '68', '69'];

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

        // 1. Pull every account in classes 6 + 7 once and bucket by code prefix.
        // Single Odoo call — works on any Odoo version (14..19+) because we
        // only use the universally-stable `code` field, never `account_type`.
        $accounts = $this->odoo->searchRead('account.account',
            [
                '|',
                ['code', '=like', '6%'],
                ['code', '=like', '7%'],
            ],
            ['id', 'code'], []);
        if ($accounts === []) {
            return $this->emptyResult();
        }

        $bucketOf = $this->bucketAccountsByCode($accounts);
        if ($bucketOf === []) {
            return $this->emptyResult();
        }
        $accountIds = array_keys($bucketOf);

        $cy = $this->fetchWindow($cyFrom, $cyTo, $accountIds, $bucketOf);
        $py = $this->fetchWindow($pyFrom, $pyTo, $accountIds, $bucketOf);

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
     * Classify each account into one of {revenue, cogs, da, opex} based on
     * its `code` prefix, longest-match-wins so '60'/'68'/'69' beat '6'.
     *
     * Returns: [account_id => bucket_label] for every classified account.
     */
    private function bucketAccountsByCode(array $accounts): array
    {
        $bucketOf = [];
        foreach ($accounts as $a) {
            $code = (string) ($a['code'] ?? '');
            if ($code === '') continue;

            // Revenue (class 7 except 79).
            if ($this->codeMatches($code, self::REVENUE_PREFIXES, self::REVENUE_EXCLUDE_PREFIXES)) {
                $bucketOf[(int) $a['id']] = 'revenue';
                continue;
            }
            // COGS (60*) — longest prefix wins.
            if ($this->codeMatches($code, self::COGS_PREFIXES, [])) {
                $bucketOf[(int) $a['id']] = 'cogs';
                continue;
            }
            // D&A (68*).
            if ($this->codeMatches($code, self::DA_PREFIXES, [])) {
                $bucketOf[(int) $a['id']] = 'da';
                continue;
            }
            // OpEx — class 6 minus the more specific buckets above.
            if ($this->codeMatches($code, self::OPEX_PREFIXES, self::OPEX_EXCLUDE_PREFIXES)) {
                $bucketOf[(int) $a['id']] = 'opex';
                continue;
            }
            // Else: unclassified (e.g. 89* income tax — handled in TaxDashboardService).
        }
        return $bucketOf;
    }

    private function codeMatches(string $code, array $includePrefixes, array $excludePrefixes): bool
    {
        foreach ($excludePrefixes as $p) if (str_starts_with($code, $p)) return false;
        foreach ($includePrefixes as $p) if (str_starts_with($code, $p)) return true;
        return false;
    }

    /**
     * Aggregate move-line balances for a window, grouped by account, then
     * by code-prefix-derived bucket. One server-side read_group call.
     */
    private function fetchWindow(\DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds, array $bucketOf): array
    {
        $domain = [
            ['parent_state', '=', 'posted'],
            ['date', '>=', $from->format('Y-m-d')],
            ['date', '<=', $to->format('Y-m-d')],
            ['account_id', 'in', $accountIds],
        ];

        // read_group aggregates on the server so we avoid pulling every line.
        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain'  => $domain,
            'fields'  => ['account_id', 'balance:sum'],
            'groupby' => ['account_id'],
            'lazy'    => false,
        ]);

        $sums = [
            'revenue' => 0.0,    // class 7 except 79 (credit-positive → negative balance in Odoo)
            'cogs'    => 0.0,    // 60* (debit-positive)
            'da'      => 0.0,    // 68*
            'opex'    => 0.0,    // class 6 except 60, 68, 69
        ];
        foreach ($rows as $r) {
            if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
            $aid = (int) $r['account_id'][0];
            $bal = (float) ($r['balance'] ?? 0);
            $bucket = $bucketOf[$aid] ?? null;
            if ($bucket === null || !isset($sums[$bucket])) continue;
            $sums[$bucket] += $bal;
        }

        // Balance sign convention: revenue is credit-positive (stored as
        // negative in Odoo's debit-positive `balance` field) — flip the
        // sign to get a positive revenue magnitude.
        $revenue = -$sums['revenue'];
        $cogs    =  $sums['cogs'];
        $da      =  $sums['da'];
        $opex    =  $sums['opex'];

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
        return 'XAF';
    }

    private function pct(float $cur, float $prev): ?float
    {
        if ($prev == 0.0) return $cur == 0.0 ? 0.0 : null;
        return (($cur - $prev) / abs($prev)) * 100.0;
    }
}
