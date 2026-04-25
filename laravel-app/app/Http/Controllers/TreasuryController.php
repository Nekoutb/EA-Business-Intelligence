<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\PayablesService;
use App\Services\ProfitLossService;
use App\Services\ReceivablesService;
use App\Services\RevenueService;

class TreasuryController extends Controller
{
    public function index()
    {
        $error        = null;
        $receivables  = null;
        $payables     = null;
        $pl           = null;
        $revenue      = null;
        $summary      = null;
        $cashEvolution = [];
        $cashflowBreakdown = [];

        try {
            $client = OdooFactory::fromConfig();
            $source = (string) config_env('REVENUE_SOURCE', 'invoice');

            $revenue = ea_cached('revenue_ytd',
                fn() => (new RevenueService($client, $source))->analyze());
            $pl = ea_cached('pl', fn() => (new ProfitLossService($client))->analyze());

            $currency   = (string) ($revenue['currency'] ?? $pl['currency'] ?? 'USD');
            $daysInYtd  = max(1, (int) ($revenue['period']['days'] ?? 1));
            $revenueYtd = (float) ($revenue['current']['total'] ?? 0);

            $receivables = ea_cached('ar',
                fn() => (new ReceivablesService($client))->analyze($revenueYtd, $daysInYtd, $currency));

            $expensesYtd = (float) ($pl['current']['cogs'] ?? 0)
                         + (float) ($pl['current']['opex'] ?? 0);
            $payables = ea_cached('ap',
                fn() => (new PayablesService($client))->analyze($expensesYtd, $daysInYtd, $currency));

            $workingCapital = ($receivables['total_ar'] ?? 0)
                            - ($payables['kpis']['total_ap'] ?? 0);
            $netCash = ($pl['current']['net_profit'] ?? 0);
            $dso = (float) ($receivables['dso'] ?? $receivables['kpis']['dso_days'] ?? 0);
            $dpo = (float) ($payables['kpis']['dpo_days'] ?? 0);
            $netConversion = $dso - $dpo;
            $collectionScore = max(0, 100 - (float) ($receivables['overdue_rate'] ?? $receivables['kpis']['overdue_rate_pct'] ?? 0));

            $runwayMonths = null;
            $monthlyBurn  = ($pl['current']['opex'] ?? 0) / max(1, $daysInYtd / 30);
            if ($monthlyBurn > 0) {
                $runwayMonths = max(0, ($receivables['total_ar'] ?? 0) / $monthlyBurn);
            }

            // ── Cash balance evolution (monthly) ─────────────────────────
            $cashEvolution = $this->buildCashEvolution($revenue, $pl, $daysInYtd);

            // ── Cash flow breakdown (where cash is spent) ────────────────
            $plCur = $pl['current'] ?? [];
            $cashflowBreakdown = $this->buildCashflowBreakdown(
                $revenueYtd,
                (float) ($plCur['cogs'] ?? 0),
                (float) ($plCur['opex'] ?? 0),
                (float) ($plCur['d_and_a'] ?? 0),
                (float) ($receivables['total_ar'] ?? 0),
                (float) ($payables['kpis']['total_ap'] ?? 0),
            );

            $summary = [
                'working_capital'      => $workingCapital,
                'collection_score'     => $collectionScore,
                'net_cash'             => $netCash,
                'dso'                  => $dso,
                'dpo'                  => $dpo,
                'conversion_cycle'     => $netConversion,
                'runway_months'        => $runwayMonths,
                'currency'             => $currency,
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('treasury', [
            'summary'            => $summary,
            'receivables'        => $receivables,
            'payables'           => $payables,
            'pl'                 => $pl,
            'cashEvolution'      => $cashEvolution,
            'cashflowBreakdown'  => $cashflowBreakdown,
            'error'              => $error,
            'active'             => 'treasury',
            'aiPage'             => 'dashboard',
        ]);
    }

    /**
     * Build a monthly cash-balance proxy from YTD revenue and expense data.
     * Distributes totals evenly across elapsed months for a smooth trend.
     */
    private function buildCashEvolution(array $revenue, array $pl, int $daysInYtd): array
    {
        $today       = new \DateTimeImmutable('today');
        $janFirst    = new \DateTimeImmutable($today->format('Y') . '-01-01');
        $currentMonth = (int) $today->format('n');

        $revenueYtd  = (float) ($revenue['current']['total'] ?? 0);
        $cogsYtd     = (float) ($pl['current']['cogs'] ?? 0);
        $opexYtd     = (float) ($pl['current']['opex'] ?? 0);
        $netYtd      = $revenueYtd - $cogsYtd - $opexYtd;

        $months = [];
        $cumulativeCash = 0;
        $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        for ($m = 1; $m <= $currentMonth; $m++) {
            $monthlyNet = $netYtd / max(1, $currentMonth);
            $cumulativeCash += $monthlyNet;
            $months[] = [
                'label'   => $monthLabels[$m - 1],
                'balance' => round($cumulativeCash, 2),
            ];
        }

        return $months;
    }

    /**
     * Build a simple cashflow breakdown showing inflows vs outflows.
     */
    private function buildCashflowBreakdown(
        float $revenue,
        float $cogs,
        float $opex,
        float $dAndA,
        float $arOutstanding,
        float $apOutstanding,
    ): array {
        return [
            ['label' => 'Revenue',       'amount' => $revenue,       'type' => 'inflow'],
            ['label' => 'Cost of Sales', 'amount' => $cogs,          'type' => 'outflow'],
            ['label' => 'Operating Exp',  'amount' => $opex,          'type' => 'outflow'],
            ['label' => 'D&A',           'amount' => $dAndA,          'type' => 'outflow'],
            ['label' => 'AR Locked',     'amount' => $arOutstanding, 'type' => 'locked'],
            ['label' => 'AP Owed',       'amount' => $apOutstanding, 'type' => 'owed'],
        ];
    }
}
