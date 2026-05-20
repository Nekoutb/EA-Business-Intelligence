<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\CashFlow16WService;
use App\Services\CashflowForecastService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Cashflow Forecast page — hosts both:
 *
 *   1. 16-week direct-method weekly forecast (Mercer-style matrix
 *      with KPI strip, chart, full band/line breakdown). Built via
 *      CashFlow16WService against live Odoo data.
 *
 *   2. Annual indirect-via-direct monthly forecast (the original
 *      analysis based on class-5 movements). Built via
 *      CashflowForecastService.
 *
 * Both load independently — one's failure doesn't kill the other.
 */
class CashflowForecastController extends Controller
{
    public function index(Request $request)
    {
        $today    = new \DateTimeImmutable();
        $year     = (int) $request->query('year', $today->format('Y'));
        $thisYear = (int) $today->format('Y');
        $year     = max(2018, min($thisYear + 2, $year));

        $data    = null;
        $data16w = null;
        $error   = null;

        // Annual monthly forecast
        try {
            $client = OdooFactory::fromConfig();
            $data = ea_cached(
                "cashflow_forecast_{$year}",
                fn () => (new CashflowForecastService($client))->analyze($year)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // 16-week weekly forecast (same Odoo connection, separate
        // cache key + error path so a failure doesn't poison the
        // monthly view above).
        try {
            $cutOff   = Carbon::now();
            $cacheKey = 'cashflow16w_' . $cutOff->format('Y-m-d');
            if ($request->boolean('refresh16w')) {
                $cacheKey .= '_' . now()->format('Hi');
            }
            $data16w = ea_cached(
                $cacheKey,
                fn () => (new CashFlow16WService(OdooFactory::fromConfig()))->build($cutOff)
            );
        } catch (\Throwable $e) {
            $data16w = ['_error' => $e->getMessage()];
        }

        return view('cashflow-forecast', [
            'active'  => 'cashflow_forecast',
            'aiPage'  => 'cashflow_forecast',
            'year'    => $year,
            'data'    => $data,
            'data16w' => $data16w,
            'error'   => $error,
        ]);
    }
}
