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
            $data = ea_cached('ap', function () {
                $c  = OdooFactory::fromConfig();
                $pl = (new ProfitLossService($c))->analyze();
                $cogs = (float) ($pl['current']['cogs'] ?? 0);
                $opex = (float) ($pl['current']['opex'] ?? 0);
                return (new PayablesService($c))->analyze(
                    $cogs + $opex, 365, (string) ($pl['currency'] ?? 'USD')
                );
            });
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('payables', [
            'data' => $data, 'error' => $error,
            'active' => 'payables', 'aiPage' => 'payables',
        ]);
    }
}
