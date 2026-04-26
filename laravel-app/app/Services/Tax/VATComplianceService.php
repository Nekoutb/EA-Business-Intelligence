<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Odoo\OdooClient;

/**
 * Tax Console — tax-regime classification + effective tax rate.
 *
 * Tax-regime classification:
 *   Pulls prior-year trial-balance turnover from accounts 70* and maps
 *   to the applicable tax regime under Cameroon CGI:
 *     ≥ 50,000,000 XAF : Real Regime (régime du réel)
 *     ≥ 10,000,000 XAF : Simplified Regime (régime simplifié)
 *     <  10,000,000 XAF : Impôt Libératoire
 *
 * Effective tax rate (YTD):
 *   ETR = balance(89*) / |balance(6*) + balance(7*) + balance(8* except 89*)|
 *   where the denominator is the absolute value of pretax income / loss
 *   built from the OHADA expense (6), revenue (7) and gain/loss (8) classes.
 *
 * Performance note (2026-04-26):
 *   Previously fetched balances per-account in N+1 search_read calls,
 *   which timed out on charts of accounts with many 70* rows. Rewritten
 *   to use a single server-aggregated `read_group` call per metric.
 */
final class VATComplianceService
{
    public function __construct(private OdooClient $odoo) {}

    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $anchor       = $today ?? new \DateTimeImmutable('today');
        $priorYearEnd = new \DateTimeImmutable(((int) $anchor->format('Y') - 1) . '-12-31');

        $priorYearTurnover = $this->fetchTrialBalanceSum(['70'], asOf: $priorYearEnd, absolute: true);
        $taxRegime         = $this->determineTaxRegime($priorYearTurnover);

        // Effective tax rate (YTD)
        $jan1 = new \DateTimeImmutable($anchor->format('Y') . '-01-01');

        // Income tax expense — debit-positive on 89*. Fetch raw signed balance
        // and use absolute value for display.
        $taxExpenseSigned = $this->fetchBalanceWindow(['89'], $jan1, $anchor);
        $taxExpense       = abs($taxExpenseSigned);

        // Pretax income/loss — sum of class 6 (expenses, debit-positive),
        // class 7 (revenues, credit-positive = stored negative), and class 8
        // (other gains/losses, mixed) MINUS the income-tax line (89*).
        // Mathematically: pretax = -(expenses) + revenues + other = -(class 6 + 7 + 8 ex 89)
        // because expenses are debit-positive (positive balance) and revenues
        // are credit-positive (negative balance). We take the absolute value.
        $pretaxRaw    = $this->fetchPretaxRaw($jan1, $anchor);
        $pretaxIncome = abs($pretaxRaw);
        // Sign of pretax (positive = profit, negative = loss).
        $pretaxSign   = $pretaxRaw < 0 ? '+' : ($pretaxRaw > 0 ? '−' : '');

        $etr = ($pretaxIncome > 0.0) ? ($taxExpense / $pretaxIncome) * 100.0 : null;

        return [
            'currency'           => $this->detectCurrency(),
            'tax_regime'         => $taxRegime,
            'regime_threshold'   => $priorYearTurnover,
            'reference_year'     => (int) $priorYearEnd->format('Y'),
            'application_year'   => (int) $anchor->format('Y'),
            'tax_expense'        => $taxExpense,
            'pretax_income'      => $pretaxIncome,
            'pretax_sign'        => $pretaxSign,        // '+' for profit, '−' for loss, '' for zero
            'effective_tax_rate' => $etr,                // null when pretax = 0
            'as_of'              => $anchor->format('Y-m-d'),
        ];
    }

    private function determineTaxRegime(float $priorYearTurnover): string
    {
        return match (true) {
            $priorYearTurnover >= 50_000_000 => 'Real Regime',
            $priorYearTurnover >= 10_000_000 => 'Simplified Regime',
            default                          => 'Impot Liberatoire Regime',
        };
    }

    /**
     * Σ posted-line balance on accounts whose code starts with any given
     * prefix, dated on or before `asOf`. Single server-aggregated read_group.
     *
     * @param bool $absolute  If true, returns abs(sum); used for revenue
     *                        (which is credit-positive / stored negative)
     *                        when we want a magnitude.
     */
    private function fetchTrialBalanceSum(array $prefixes, \DateTimeImmutable $asOf, bool $absolute): float
    {
        $accountIds = $this->accountIdsByPrefix($prefixes);
        if ($accountIds === []) return 0.0;

        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['date', '<=', $asOf->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['balance:sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);

        $bal = (float) ($rows[0]['balance'] ?? 0);
        return $absolute ? abs($bal) : $bal;
    }

    /**
     * Σ posted-line balance on accounts matching any of `prefixes`, dated
     * within the [from, to] window inclusive. Single read_group.
     */
    private function fetchBalanceWindow(array $prefixes, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $accountIds = $this->accountIdsByPrefix($prefixes);
        if ($accountIds === []) return 0.0;

        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['balance:sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);

        return (float) ($rows[0]['balance'] ?? 0);
    }

    /**
     * Σ balance on classes 6 + 7 + 8, EXCLUDING any account whose code
     * starts with 89 (income tax). Returns the raw signed value; caller
     * takes abs() for display.
     */
    private function fetchPretaxRaw(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        // Load classes 6/7/8 and remove 89* in PHP — cheaper than building a
        // negation domain on the server.
        $rows = $this->odoo->searchRead('account.account', [
            '|', '|',
            ['code', '=like', '6%'],
            ['code', '=like', '7%'],
            ['code', '=like', '8%'],
        ], ['id', 'code'], []);

        if ($rows === []) return 0.0;

        $accountIds = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '' || str_starts_with($code, '89')) continue;
            $accountIds[] = (int) $r['id'];
        }
        if ($accountIds === []) return 0.0;

        $agg = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['balance:sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);

        return (float) ($agg[0]['balance'] ?? 0);
    }

    /**
     * Resolve all account ids whose code starts with any given prefix.
     */
    private function accountIdsByPrefix(array $prefixes): array
    {
        if ($prefixes === []) return [];

        // Build OR domain over the prefixes: |, |, ..., [code, =like, P%]...
        $domain = [];
        for ($i = 0; $i < count($prefixes) - 1; $i++) $domain[] = '|';
        foreach ($prefixes as $p) $domain[] = ['code', '=like', $p . '%'];

        $rows = $this->odoo->searchRead('account.account', $domain, ['id'], []);
        return array_map(fn ($r) => (int) $r['id'], $rows);
    }

    private function detectCurrency(): string
    {
        try {
            $companies = $this->odoo->searchRead('res.company', [], ['currency_id'], [], 1);
            if (!empty($companies) && !empty($companies[0]['currency_id'])) {
                return $companies[0]['currency_id'][1] ?? 'XAF';
            }
        } catch (\Throwable $e) {
            // Fall through to default.
        }
        return 'XAF';
    }
}
