<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ProfitLossService;
use Illuminate\Http\Request;

class ProfitLossController extends Controller
{
    public function index(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $period = [
            'type'  => $request->query('period', 'ytd'),
            'year'  => (int) $request->query('year',  $today->format('Y')),
            'month' => (int) $request->query('month', $today->format('m')),
        ];
        [,, $label] = ea_resolve_period($today, $period);

        $data = null; $error = null;
        try {
            $data = ea_cached('pl_' . md5(json_encode($period)), function () use ($period) {
                return (new ProfitLossService(OdooFactory::fromConfig()))->analyze(null, $period);
            });
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('profit-loss', [
            'data' => $data, 'error' => $error,
            'active' => 'pl',   'aiPage' => 'pl',
            'period' => $period, 'periodLabel' => $label,
        ]);
    }
}
