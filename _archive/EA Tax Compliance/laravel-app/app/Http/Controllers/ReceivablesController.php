<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ReceivablesService;
use App\Services\RevenueService;

class ReceivablesController extends Controller
{
    public function index()
    {
        $data = null; $error = null;
        try {
            $data = ea_cached('ar', function () {
                $c = OdooFactory::fromConfig();
                $s = (string) config_env('REVENUE_SOURCE', 'invoice');
                $r = (new RevenueService($c, $s))->analyze();
                return (new ReceivablesService($c))->analyze(
                    (float) ($r['current']['total'] ?? 0),
                    (int)   ($r['period']['days']  ?? 1),
                    (string)($r['currency']         ?? 'USD'),
                );
            });
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('receivables', [
            'data' => $data, 'error' => $error,
            'active' => 'receivables', 'aiPage' => 'receivables',
        ]);
    }
}
