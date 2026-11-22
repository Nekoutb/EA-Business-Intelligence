<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\PayablesService;
use App\Services\ProfitLossService;

class PayablesController extends Controller
{
    public function index()
    {
        $data = null; $error = null;
        try {
            // Cache key bumped to ap_v3: the DPO trend is now the RUNNING
            // cumulative DPO (reconciles to the headline dpo_days), not the
            // monthly point-in-time figure.
            // ap_v5: credit-purchases DPO for clients on DSO_DPO_METHOD = credit90.
            $data = ea_cached('ap_v5', function () {
                $c  = OdooFactory::fromConfig();
                $pl = (new ProfitLossService($c))->analyze();
                $exp = max(1.0,
                      (float) ($pl['current']['cogs'] ?? 0)
                    + (float) ($pl['current']['opex'] ?? 0)
                    + (float) ($pl['current']['d_and_a'] ?? 0)
                );
                $svc = new PayablesService($c);
                $ap  = $svc->analyze($exp, 365, (string) ($pl['currency'] ?? 'XAF'));

                // Running cumulative DPO by month — converges to kpis.dpo_days.
                $ap['dpo_trend'] = \App\Services\CreditDaysService::enabled()
                    ? (new \App\Services\CreditDaysService($c))->monthlySeries()['dpo']
                    : $svc->dpoRunningSeries((float) ($ap['kpis']['total_ap'] ?? 0), $exp, 365);

                return $ap;
            });
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('payables', [
            'data' => $data, 'error' => $error,
            'active' => 'payables', 'aiPage' => 'payables',
        ]);
    }
}
