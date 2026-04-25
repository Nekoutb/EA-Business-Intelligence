<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ProfitLossService;
use App\Services\RevenueService;
use App\Services\ReceivablesService;

class DashboardController extends Controller
{
    public function index()
    {
        $source = (string) config_env('REVENUE_SOURCE', 'invoice');
        $data         = null;
        $pl           = null;
        $receivables  = null;
        $error        = null;

        try {
            $client = OdooFactory::fromConfig();
            $data   = ea_cached('revenue_ytd',
                fn() => (new RevenueService($client, $source))->analyze());
            try {
                $pl = ea_cached('pl',
                    fn() => (new ProfitLossService($client))->analyze());
            } catch (\Throwable $e) {
                $pl = null;
            }

            if ($data) {
                try {
                    $totalRevenue = (float) ($data['current']['total'] ?? 0);
                    $periodDays   = max(1, (int) ($data['period']['days'] ?? 1));
                    $currency     = (string) ($data['currency'] ?? 'USD');
                    $receivables  = ea_cached('ar', function () use ($client, $totalRevenue, $periodDays, $currency) {
                        return (new ReceivablesService($client))->analyze($totalRevenue, $periodDays, $currency);
                    });
                } catch (\Throwable $e) {
                    $receivables = null;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('dashboard', [
            'data'   => $data,
            'pl'     => $pl,
            'receivables' => $receivables,
            'error'  => $error,
            'active' => 'dashboard',
        ]);
    }

    public function sync()
    {
        ea_cache_clear();
        return back()->with('flash', ['type' => 'success', 'msg' => 'Data refreshed — next load will hit Odoo live.']);
    }
}
