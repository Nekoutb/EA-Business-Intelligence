<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\RevenueService;
use Illuminate\Http\Request;

class RevenueController extends Controller
{
    public function index(Request $request)
    {
        $today    = new \DateTimeImmutable();
        $period   = [
            'type'  => $request->query('period', 'ytd'),
            'year'  => (int) $request->query('year',  $today->format('Y')),
            'month' => (int) $request->query('month', $today->format('m')),
        ];
        [$from, $to, $label] = ea_resolve_period($today, $period);

        $cacheKey = 'revenue_' . md5(json_encode($period));
        $data  = null; $error = null; $profitability = null;
        try {
            $client = OdooFactory::fromConfig();
            $source = (string) config_env('REVENUE_SOURCE', 'invoice');
            $svc    = new RevenueService($client, $source);

            $data = ea_cached($cacheKey, fn() => $svc->analyze(null, $period));

            $profitability = ea_cached($cacheKey . '_profit', fn() => $svc->productProfitability($data));

            $topDiscounted = ea_cached($cacheKey . '_disc', fn() => $svc->topDiscountedProducts($data));
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('revenue', [
            'data'          => $data,
            'profitability' => $profitability,
            'topDiscounted' => $topDiscounted ?? null,
            'error'         => $error,
            'active'        => 'revenue',
            'aiPage'        => 'revenue',
            'period'        => $period,
            'periodLabel'   => $label,
        ]);
    }
}
