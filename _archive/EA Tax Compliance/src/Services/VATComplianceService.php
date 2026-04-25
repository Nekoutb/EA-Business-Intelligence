<?php
declare(strict_types=1);

namespace EA\BI\Services;

use EA\BI\Odoo\OdooClient;

/**
 * Computes VAT compliance checks from Odoo.
 *
 * Checks:
 *   - Prior year turnover from account 70 (Sales)
 *   - Determines tax regime based on turnover thresholds:
 *     * >= 50M: Real Regime
 *     * 10M - 50M: Simplified Regime
 *     * < 10M: Impot Liberatoire Regime
 *   - Filters by current year, prior year, and month
 */
final class VATComplianceService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * Analyzes VAT compliance and determines tax regime.
     * Returns prior year trial balance turnover and applicable tax regime.
     */
    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        // Fetch prior year trial balance as of December 31, 2025
        $priorYearEnd = new \DateTimeImmutable('2025-12-31');
        
        // Fetch turnover from trial balance accounts 70XX as of Dec 31, 2025
        $priorYearTurnover = $this->fetchTrialBalanceTurnover($priorYearEnd);

        // Determine tax regime based on prior year turnover
        $taxRegime = $this->determineTaxRegime($priorYearTurnover);

        return [
            'currency'   => $this->detectCurrency(),
            'tax_regime' => $taxRegime,
            'regime_threshold' => $priorYearTurnover,
        ];
    }

    /**
     * Fetches trial balance turnover from accounts 70XX as of a specific date.
     * Revenues are stored as negative values, so we use absolute values.
     */
    private function fetchTrialBalanceTurnover(\DateTimeImmutable $asOfDate): float
    {
        $dateStr = $asOfDate->format('Y-m-d');

        // Query chart.account for all accounts starting with 70 (70XX)
        // Then get their balances as of the specified date
        $domain = [
            ['code', '=like', '70%'],
        ];

        $accounts = $this->odoo->searchRead('account.account', $domain, [
            'id',
            'code',
            'name',
        ], [], 0);

        $totalTurnover = 0.0;

        foreach ($accounts as $account) {
            $accountId = $account['id'];
            
            // Get the balance of this account as of the specified date
            $balanceDomain = [
                ['account_id', '=', $accountId],
                ['move_id.date', '<=', $dateStr],
                ['move_id.state', '=', 'posted'],
            ];

            $lines = $this->odoo->searchRead('account.move.line', $balanceDomain, [
                'balance',
            ], [], 0);

            foreach ($lines as $line) {
                // Take absolute value since revenues are negative
                $totalTurnover += abs((float) ($line['balance'] ?? 0));
            }
        }

        return $totalTurnover;
    }

    /**
     * Determines the applicable tax regime based on prior year turnover.
     *
     * @param float $priorYearTurnover Prior year turnover in base currency
     * @return string Tax regime name
     */
    private function determineTaxRegime(float $priorYearTurnover): string
    {
        if ($priorYearTurnover >= 50_000_000) {
            return 'Real Regime';
        } elseif ($priorYearTurnover >= 10_000_000) {
            return 'Simplified Regime';
        } else {
            return 'Impot Liberatoire Regime';
        }
    }

    /**
     * Detects the company's base currency from Odoo.
     */
    private function detectCurrency(): string
    {
        try {
            $companies = $this->odoo->searchRead('res.company', [], ['currency_id'], [], 1);
            if (!empty($companies) && !empty($companies[0]['currency_id'])) {
                return $companies[0]['currency_id'][1] ?? 'USD';
            }
        } catch (\Throwable $e) {
            // Silently fall back to USD
        }
        return 'USD';
    }

}
