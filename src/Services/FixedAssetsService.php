<?php
declare(strict_types=1);

namespace EA\BI\Services;

use EA\BI\Odoo\OdooClient;

/**
 * Fixed-assets portfolio KPIs.
 *
 * Reads Odoo's `account.asset` (enterprise "Accounting - Assets" module).
 * When the module is not installed or the API user lacks access, the service
 * returns a graceful "no data" shape so the page can still render.
 *
 * KPIs:
 *   1. gross_value          Sum of original_value on active assets
 *   2. net_book_value       Sum of value_residual
 *   3. accumulated_depr     gross - NBV
 *   4. depreciation_ytd     GL charge to expense_depreciation accounts YTD
 *   5. active_asset_count   # of running assets
 *   6. avg_asset_age_years  Weighted by original value
 */
final class FixedAssetsService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $today = null): array
    {
        $today  = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $currency = $this->detectCurrency();
        $cyFrom = $today->modify('first day of January')->setTime(0, 0, 0);

        // 1. Pull all active assets.
        $assets = [];
        $module = 'present';
        try {
            $offset = 0; $limit = 500;
            do {
                $batch = $this->odoo->searchRead(
                    'account.asset',
                    [['state', 'in', ['open', 'close', 'paused']]],
                    ['id', 'name', 'original_value', 'value_residual', 'salvage_value',
                     'acquisition_date', 'state', 'method_number', 'method_period',
                     'asset_type'],
                    ['offset' => $offset, 'limit' => $limit, 'order' => 'original_value desc']
                );
                foreach ($batch as $a) $assets[] = $a;
                $offset += $limit;
            } while (count($batch) === $limit);
        } catch (\Throwable $e) {
            // Model not available (asset module not installed) or permission
            // denied. Return an empty shape so the view can explain.
            return $this->emptyResult($currency, 'The `account.asset` model is not available on this Odoo instance (the Assets module may not be installed, or your API key lacks read access).');
        }

        $grossValue   = 0.0;
        $netBookValue = 0.0;
        $weightedAgeDays = 0.0;
        $activeCount  = 0;
        $byType       = []; // asset_type => [gross, nbv, count]
        $topAssets    = [];

        foreach ($assets as $a) {
            $gross = (float) ($a['original_value'] ?? 0);
            $nbv   = (float) ($a['value_residual'] ?? 0);
            $grossValue   += $gross;
            $netBookValue += $nbv;

            $state = (string) ($a['state'] ?? '');
            if ($state === 'open' || $state === 'paused') $activeCount++;

            $acq = (string) ($a['acquisition_date'] ?? '');
            if ($acq !== '') {
                try {
                    $d = new \DateTimeImmutable($acq);
                    $ageDays = max(0, (int) $d->diff($today)->days);
                    $weightedAgeDays += $ageDays * $gross;
                } catch (\Throwable $e) { /* skip */ }
            }

            $type = (string) ($a['asset_type'] ?? 'asset');
            if (!isset($byType[$type])) $byType[$type] = ['gross'=>0.0,'nbv'=>0.0,'count'=>0];
            $byType[$type]['gross'] += $gross;
            $byType[$type]['nbv']   += $nbv;
            $byType[$type]['count']++;

            $topAssets[] = [
                'name'       => (string) ($a['name'] ?? ('#'.$a['id'])),
                'gross'      => $gross,
                'nbv'        => $nbv,
                'acquired'   => $acq,
                'state'      => $state,
                'type'       => $type,
                'depr_pct'   => $gross > 0 ? (($gross - $nbv) / $gross) * 100.0 : 0.0,
            ];
        }

        // Top 10 by NBV.
        usort($topAssets, fn($a, $b) => $b['nbv'] <=> $a['nbv']);
        $topAssets = array_slice($topAssets, 0, 10);

        $accumulated = $grossValue - $netBookValue;
        $avgAgeYears = $grossValue > 0 ? ($weightedAgeDays / $grossValue) / 365.25 : 0.0;

        // 2. YTD depreciation charge from the GL (account_type = expense_depreciation).
        $depreciationYtd = 0.0;
        try {
            $daAccounts = $this->odoo->searchRead(
                'account.account',
                [['account_type', '=', 'expense_depreciation']],
                ['id'], []
            );
            $ids = array_map(fn($r) => (int) $r['id'], $daAccounts);
            if ($ids) {
                $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                    'domain' => [
                        ['parent_state', '=', 'posted'],
                        ['date', '>=', $cyFrom->format('Y-m-d')],
                        ['date', '<=', $today->format('Y-m-d')],
                        ['account_id', 'in', $ids],
                    ],
                    'fields'  => ['balance:sum'],
                    'groupby' => [],
                    'lazy'    => false,
                ]);
                if (isset($rows[0]['balance'])) $depreciationYtd = (float) $rows[0]['balance'];
            }
        } catch (\Throwable $e) { /* keep 0 */ }

        return [
            'currency' => $currency,
            'as_of'    => $today->format('Y-m-d'),
            'module'   => $module,
            'message'  => null,
            'kpis' => [
                'gross_value'         => $grossValue,
                'net_book_value'      => $netBookValue,
                'accumulated_depr'    => $accumulated,
                'depreciation_ytd'    => $depreciationYtd,
                'active_asset_count'  => $activeCount,
                'avg_asset_age_years' => $avgAgeYears,
                'depreciation_ratio_pct' => $grossValue > 0 ? ($accumulated / $grossValue) * 100.0 : 0.0,
            ],
            'by_type'   => $byType,
            'top_assets' => $topAssets,
            'asset_count' => count($assets),
        ];
    }

    private function emptyResult(string $currency, string $message): array
    {
        return [
            'currency' => $currency,
            'as_of'    => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'module'   => 'absent',
            'message'  => $message,
            'kpis' => [
                'gross_value' => 0.0, 'net_book_value' => 0.0, 'accumulated_depr' => 0.0,
                'depreciation_ytd' => 0.0, 'active_asset_count' => 0,
                'avg_asset_age_years' => 0.0, 'depreciation_ratio_pct' => 0.0,
            ],
            'by_type'     => [],
            'top_assets'  => [],
            'asset_count' => 0,
        ];
    }

    private function detectCurrency(): string
    {
        try {
            $uid = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
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
}
