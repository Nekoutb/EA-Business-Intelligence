<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\PayablesService;
use App\Services\ProfitLossService;
use App\Services\WorkingCapitalService;

class PayablesController extends Controller
{
    public function index()
    {
        $data = null; $error = null;
        try {
            // Cache key bumped to ap_v2: the old 'ap' entry + view read a flat
            // shape, but PayablesService nests KPIs under 'kpis'. New key also
            // carries the monthly DPO trend.
            $data = ea_cached('ap_v2', function () {
                $c  = OdooFactory::fromConfig();
                $pl = (new ProfitLossService($c))->analyze();
                $exp = (float) ($pl['current']['cogs'] ?? 0)
                     + (float) ($pl['current']['opex'] ?? 0)
                     + (float) ($pl['current']['d_and_a'] ?? 0);
                $ap = (new PayablesService($c))->analyze(
                    max(1.0, $exp), 365, (string) ($pl['currency'] ?? 'XAF')
                );

                // Monthly DPO evolution from the liquidity-trend series.
                $dpoTrend = [];
                try {
                    foreach (((new WorkingCapitalService($c))->liquidityTrend(12)['series'] ?? []) as $p) {
                        if (($p['dpo'] ?? null) !== null) {
                            $dpoTrend[] = ['label' => (string) ($p['label'] ?? ''), 'dpo' => (float) $p['dpo']];
                        }
                    }
                } catch (\Throwable $e) { /* trend optional */ }
                $ap['dpo_trend'] = $dpoTrend;

                return $ap;
            });
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('payables', [
            'data' => $data, 'error' => $error,
            'active' => 'payables', 'aiPage' => 'payables',
        ]);
    }
}
