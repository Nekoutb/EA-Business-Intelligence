<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Investment Analysis — capital deployment & returns lens.
 *
 * KPIs:
 *   Capex YTD              — net additions to fixed assets (class 2 movements)
 *   Depreciation YTD       — D&A booked YTD (class 68)
 *   Net Investment         — Capex − Depreciation (positive = growing asset
 *                            base; negative = liquidating)
 *   Capital Employed       — Equity (class 10) + Long-term debt (class 16)
 *   ROCE                   — EBIT TTM / Capital Employed
 *   Asset Turnover         — Revenue TTM / Total Assets (classes 2 + 3 + 4 + 5)
 *   Capex / Revenue        — How much of the top line is being reinvested
 *   Depreciation / Capex   — Replacement-rate signal (>100% means assets
 *                            are wearing down faster than being replaced)
 *
 * Methodology — code-prefix based, version-agnostic across Odoo 14..19+.
 *
 *   Class 2  — Fixed assets (debit-normal). Year-on-year delta = capex.
 *   Class 68 — D&A (debit-normal).
 *   Class 10 — Capital, reserves (credit-normal).
 *   Class 16 — Long-term debt (credit-normal).
 *   Class 3,4,5 — Current assets.
 *   Class 7 — Revenue (credit-normal).
 *   Class 60 — COGS (debit-normal).
 *   Class 6 except 68/69 — OpEx + COGS combined for EBIT compute.
 */
final class InvestmentAnalysisService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $asOf = null): array
    {
        $asOf = $asOf ?? new \DateTimeImmutable('today');
        $year = (int) $asOf->format('Y');
        $yearStart = new \DateTimeImmutable($year . '-01-01');

        // ── Balance-sheet snapshots ──
        $fixedAssetsNow   = $this->fetchBalance('2',  $asOf);
        $fixedAssetsStart = $this->fetchBalance('2',  $yearStart);
        $equity           = -$this->fetchBalance('10', $asOf); // credit-normal
        $ltDebt           = -$this->fetchBalance('16', $asOf);
        $totalAssets      = $this->fetchBalance('2',  $asOf)
                          + $this->fetchBalance('3',  $asOf)
                          + max(0.0, $this->fetchBalance('4',  $asOf))
                          + max(0.0, $this->fetchBalance('5',  $asOf));

        // ── P&L year-to-date ──
        $revenueYtd = -$this->fetchPlSum('7',  $yearStart, $asOf, ['77', '79']);
        $cogsYtd    =  $this->fetchPlSum('60', $yearStart, $asOf);
        $opexYtd    =  $this->fetchPlSum('6',  $yearStart, $asOf, ['60', '67', '68', '69']);
        $daYtd      =  $this->fetchPlSum('68', $yearStart, $asOf);
        $ebitYtd    =  $revenueYtd - $cogsYtd - $opexYtd - $daYtd;

        // ── TTM (trailing-12-month) for ROCE / asset turnover ──
        $ttmFrom    = $asOf->modify('-1 year +1 day');
        $revenueTtm = -$this->fetchPlSum('7',  $ttmFrom, $asOf, ['77', '79']);
        $cogsTtm    =  $this->fetchPlSum('60', $ttmFrom, $asOf);
        $opexTtm    =  $this->fetchPlSum('6',  $ttmFrom, $asOf, ['60', '67', '68', '69']);
        $daTtm      =  $this->fetchPlSum('68', $ttmFrom, $asOf);
        $ebitTtm    =  $revenueTtm - $cogsTtm - $opexTtm - $daTtm;

        // ── Derived KPIs ──
        // Capex = increase in net fixed assets + depreciation booked YTD
        // (because fixed assets balance = additions − depreciation)
        $netDeltaFa = $fixedAssetsNow - $fixedAssetsStart;
        $capexYtd   = $netDeltaFa + $daYtd;

        $netInvestment = $capexYtd - $daYtd;
        $capitalEmployed = $equity + $ltDebt;
        $roce          = $capitalEmployed > 0 ? 100.0 * $ebitTtm / $capitalEmployed : null;
        $assetTurnover = $totalAssets > 0 ? $revenueTtm / $totalAssets : null;
        $capexRevenue  = $revenueYtd > 0 ? 100.0 * $capexYtd / $revenueYtd : null;
        $replacement   = $capexYtd > 0 ? 100.0 * $daYtd / $capexYtd : null;

        return [
            'currency'          => 'XAF',
            'as_of'             => $asOf->format('Y-m-d'),
            'year'              => $year,
            'fetched_at'        => now()->toIso8601String(),

            'kpis' => [
                'capex_ytd'         => ['value' => $capexYtd,        'label' => 'Capex YTD',
                                        'unit' => 'XAF', 'better' => 'context',
                                        'sub' => 'Net fixed-asset additions plus D&A booked'],
                'depreciation_ytd'  => ['value' => $daYtd,           'label' => 'Depreciation YTD',
                                        'unit' => 'XAF', 'better' => 'context',
                                        'sub' => 'Class 68 booked YTD'],
                'net_investment'    => ['value' => $netInvestment,   'label' => 'Net Investment',
                                        'unit' => 'XAF', 'better' => 'higher',
                                        'sub' => 'Capex − Depreciation. Positive = asset base growing.'],
                'capital_employed'  => ['value' => $capitalEmployed, 'label' => 'Capital Employed',
                                        'unit' => 'XAF', 'better' => 'context',
                                        'sub' => 'Equity + Long-term debt'],
                'roce'              => ['value' => $roce,            'label' => 'ROCE',
                                        'unit' => '%',   'better' => 'higher',
                                        'sub' => 'EBIT (TTM) ÷ Capital Employed'],
                'asset_turnover'    => ['value' => $assetTurnover,   'label' => 'Asset Turnover',
                                        'unit' => 'x',   'better' => 'higher',
                                        'sub' => 'Revenue (TTM) ÷ Total Assets'],
                'capex_to_revenue'  => ['value' => $capexRevenue,    'label' => 'Capex / Revenue',
                                        'unit' => '%',   'better' => 'context',
                                        'sub' => 'YTD reinvestment intensity'],
                'replacement_rate'  => ['value' => $replacement,     'label' => 'Replacement Rate',
                                        'unit' => '%',   'better' => 'mixed',
                                        'sub' => 'D&A ÷ Capex. > 100% = assets wearing down faster than replaced'],
            ],

            'components' => [
                'fixed_assets_now'   => $fixedAssetsNow,
                'fixed_assets_start' => $fixedAssetsStart,
                'equity'             => $equity,
                'lt_debt'            => $ltDebt,
                'total_assets'       => $totalAssets,
                'revenue_ttm'        => $revenueTtm,
                'ebit_ttm'           => $ebitTtm,
                'revenue_ytd'        => $revenueYtd,
                'ebit_ytd'           => $ebitYtd,
            ],
        ];
    }

    /**
     * Cumulative balance for accounts whose code starts with $prefix as
     * of $asOf.
     */
    private function fetchBalance(string $prefix, \DateTimeImmutable $asOf): float
    {
        try {
            $accts = $this->odoo->searchRead('account.account',
                [['code', '=like', $prefix . '%']], ['id'], []);
            if (empty($accts)) return 0.0;
            $ids = array_column($accts, 'id');
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['parent_state', '=', 'posted'],
                    ['date', '<=', $asOf->format('Y-m-d')],
                    ['account_id', 'in', $ids],
                ],
                'fields'  => ['balance:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            return (float) ($rows[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Sum of move-line balance for accounts whose code starts with
     * $includePrefix and does NOT start with any of $excludePrefixes,
     * over the date range [$from, $to].
     */
    private function fetchPlSum(string $includePrefix, \DateTimeImmutable $from, \DateTimeImmutable $to, array $excludePrefixes = []): float
    {
        try {
            $accts = $this->odoo->searchRead('account.account',
                [['code', '=like', $includePrefix . '%']], ['id', 'code'], []);
            $ids = [];
            foreach ($accts as $a) {
                $code = (string) ($a['code'] ?? '');
                $exclude = false;
                foreach ($excludePrefixes as $p) if (str_starts_with($code, $p)) { $exclude = true; break; }
                if (!$exclude) $ids[] = (int) $a['id'];
            }
            if (empty($ids)) return 0.0;
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $from->format('Y-m-d')],
                    ['date', '<=', $to->format('Y-m-d')],
                    ['account_id', 'in', $ids],
                ],
                'fields'  => ['balance:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            return (float) ($rows[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
