<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\CustomerInsightsService;
use App\Services\RevenueService;
use Illuminate\Http\Request;

class CustomerInsightsController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $period = [
            'type'  => $request->query('period', 'ytd'),
            'year'  => (int) $request->query('year',  $today->format('Y')),
            'month' => (int) $request->query('month', $today->format('m')),
        ];

        $cacheKey = 'customers_' . md5(json_encode($period));
        $analysis = null;
        $insights = null;
        $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $source = (string) config_env('REVENUE_SOURCE', 'invoice');
            $revenueService = new RevenueService($client, $source);
            $customerService = new CustomerInsightsService();

            $analysis = ea_cached($cacheKey, fn() => $revenueService->analyze(null, $period));
            $insights = $customerService->analyze($analysis);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        [$from, $to, $label] = ea_resolve_period($today, $period);

        return view('customer-insights', [
            'data'             => $analysis,
            'insights'         => $insights,
            'error'            => $error,
            'active'           => 'customers',
            'aiPage'           => 'customers',
            'period'           => $period,
            'periodLabel'      => $label,
        ]);
    }
}
