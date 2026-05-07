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
            // OdooClient is passed to the customer service so it can run
            // the per-customer margin computation (line-level pull from
            // account.move.line + product.product.standard_price) that
            // drives the profitability scatter chart.
            $customerService = new CustomerInsightsService($client);

            $analysis = ea_cached($cacheKey, fn() => $revenueService->analyze(null, $period));
            // Resolve the period window once so we can pass [$from, $to]
            // to the service (used for the margin Odoo round-trip).
            [$from, $to, $label] = ea_resolve_period($today, $period);
            $insights = ea_cached($cacheKey . '_insights',
                fn () => $customerService->analyze($analysis, $from, $to));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if (!isset($from, $to, $label)) {
            [$from, $to, $label] = ea_resolve_period($today, $period);
        }

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
