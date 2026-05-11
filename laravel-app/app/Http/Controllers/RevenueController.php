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

        // Cache key prefix bumped to revenue_ht_v1_ on 2026-05-11 when
        // RevenueService switched amount_total_signed (TTC) → amount_untaxed_signed
        // (HT). Bypasses stale TTC-era cache entries on deploy.
        $cacheKey = 'revenue_ht_v1_' . md5(json_encode($period));
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
