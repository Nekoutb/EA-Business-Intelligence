<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\CashFlowService;
use App\Services\PayablesService;
use App\Services\ProfitLossService;
use App\Services\ReceivablesService;
use App\Services\RevenueService;

class TreasuryController extends Controller
{
    public function index()
    {
        $error           = null;
        $receivables     = null;
        $payables        = null;
        $pl              = null;
        $revenue         = null;
        $summary         = null;
        $cashEvolution   = [];
        $cashflowDirect  = null;
        $journalBalances = null;

        try {
            $client = OdooFactory::fromConfig();
            $source = (string) config_env('REVENUE_SOURCE', 'invoice');

            $cashFlowSvc = new CashFlowService($client);
            $cashflowDirect  = ea_cached('cf_direct',     fn () => $cashFlowSvc->directCashFlow());
            $cashEvolution   = ea_cached('cf_evolution',  fn () => $cashFlowSvc->cashEvolution());
            $journalBalances = ea_cached('cf_journals',   fn () => $cashFlowSvc->journalBalances());

            $revenue = ea_cached('revenue_ytd',
                fn() => (new RevenueService($client, $source))->analyze());
            $pl = ea_cached('pl', fn() => (new ProfitLossService($client))->analyze());

            $currency   = (string) ($cashflowDirect['currency'] ?? $revenue['currency'] ?? $pl['currency'] ?? 'XAF');
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
            'summary'           => $summary,
            'receivables'       => $receivables,
            'payables'          => $payables,
            'pl'                => $pl,
            'cashEvolution'     => $cashEvolution,
            'cashflowDirect'    => $cashflowDirect,
            'journalBalances'   => $journalBalances,
            'error'             => $error,
            'active'            => 'treasury',
            'aiPage'            => 'dashboard',
        ]);
    }
}
