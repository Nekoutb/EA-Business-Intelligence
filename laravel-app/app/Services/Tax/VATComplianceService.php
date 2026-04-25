<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Odoo\OdooClient;

/**
 * VAT compliance / tax-regime classification.
 *
 * Pulls the prior-year trial-balance turnover from accounts 70XX in Odoo and
 * maps it to the applicable tax regime under the CEMAC / Cameroon CGI:
 *   - >= 50,000,000 XAF : Real Regime (régime du réel)
 *   - >= 10,000,000 XAF : Simplified Regime (régime simplifié)
 *   - <  10,000,000 XAF : Impôt Libératoire
 *
 * Ported from EA Tax Compliance / src / Services / VATComplianceService.
 */
final class VATComplianceService
{
    public function __construct(private OdooClient $odoo)
    {
    }

    /**
     * Returns currency, applicable tax regime, and the regime threshold
     * (i.e. the prior-year turnover used to classify the tenant).
     *
     * The "today" arg is accepted for symmetry with other services but the
     * method always anchors on the previous calendar year-end.
     */
    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $anchor = $today ?? new \DateTimeImmutable('today');
        // Prior year-end is December 31 of the previous calendar year.
        $priorYearEnd = new \DateTimeImmutable(($anchor->format('Y') - 1) . '-12-31');

        $priorYearTurnover = $this->fetchTrialBalanceTurnover($priorYearEnd);
        $taxRegime         = $this->determineTaxRegime($priorYearTurnover);

        return [
            'currency'         => $this->detectCurrency(),
            'tax_regime'       => $taxRegime,
            'regime_threshold' => $priorYearTurnover,
            'reference_year'   => (int) $priorYearEnd->format('Y'),
            'application_year' => (int) $anchor->format('Y'),
        ];
    }

    /**
     * Sum balances of all 70XX accounts up to a given date. Revenues are
     * stored as negative ledger values so we return absolute totals.
     */
    private function fetchTrialBalanceTurnover(\DateTimeImmutable $asOfDate): float
    {
        $dateStr = $asOfDate->format('Y-m-d');

        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', '70%']],
            ['id', 'code', 'name'],
            [],
            0
        );

        $total = 0.0;
        foreach ($accounts as $account) {
            $lines = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['account_id', '=', $account['id']],
                    ['move_id.date', '<=', $dateStr],
                    ['move_id.state', '=', 'posted'],
                ],
                ['balance'],
                [],
                0
            );
            foreach ($lines as $line) {
                $total += abs((float) ($line['balance'] ?? 0));
            }
        }
        return $total;
    }

    private function determineTaxRegime(float $priorYearTurnover): string
    {
        return match (true) {
            $priorYearTurnover >= 50_000_000 => 'Real Regime',
            $priorYearTurnover >= 10_000_000 => 'Simplified Regime',
            default                          => 'Impot Liberatoire Regime',
        };
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
