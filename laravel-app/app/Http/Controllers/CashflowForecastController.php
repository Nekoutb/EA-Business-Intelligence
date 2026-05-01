<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\CashflowForecastService;
use Illuminate\Http\Request;

class CashflowForecastController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));
        $thisYear = (int) $today->format('Y');
        $year = max(2018, min($thisYear + 2, $year));

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $data = ea_cached(
                "cashflow_forecast_{$year}",
                fn () => (new CashflowForecastService($client))->analyze($year)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('cashflow-forecast', [
            'active' => 'cashflow_forecast',
            'year'   => $year,
            'data'   => $data,
            'error'  => $error,
        ]);
    }
}
