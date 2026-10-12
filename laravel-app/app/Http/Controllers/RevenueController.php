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

        // Revenue streams by nature (one per class-7 account) — admin toggle.
        // Window: start of the period's year to the end of the period.
        $tenant     = tenant();
        $showRatios = !$tenant || $tenant->dashboardKpiEnabled('revenue_cost_ratios');
        $streams    = null;
        if ($tenant && $tenant->dashboardKpiEnabled('revenue_streams')) {
            try {
                $ref = (string) config_env('KPI_FILE_REF_FIELD', 'move_name');
                $ref = in_array($ref, \App\Services\FileUnitEconomics::FIELDS, true) ? $ref : 'move_name';
                $sFrom = $to->format('Y') . '-01-01';
                $streams = ea_cached('rev_streams_v1_' . md5(json_encode($period)), fn () =>
                    (new \App\Services\FileUnitEconomics(OdooFactory::fromConfig(), $ref))->streams($sFrom, $to->format('Y-m-d')));
            } catch (\Throwable $e) { $streams = null; }
        }

        return view('revenue', [
            'data'          => $data,
            'profitability' => $profitability,
            'topDiscounted' => $topDiscounted ?? null,
            'error'         => $error,
            'active'        => 'revenue',
            'aiPage'        => 'revenue',
            'period'        => $period,
            'periodLabel'   => $label,
            'streams'       => $streams,
            'showRatios'    => $showRatios,
        ]);
    }
}
