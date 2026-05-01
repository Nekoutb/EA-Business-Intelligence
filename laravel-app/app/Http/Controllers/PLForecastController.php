<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\PLForecastService;
use Illuminate\Http\Request;

/**
 * P&L Forecast — full-year monthly P&L with prior months as actuals
 * and remaining months as forecast (SMLY with trailing-3-mean fallback).
 *
 * Cached per fiscal year. Year selectable via ?year=2026.
 */
class PLForecastController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));
        // Sane bounds — no point requesting decades-old data or far-future
        $thisYear = (int) $today->format('Y');
        $year = max(2018, min($thisYear + 2, $year));

        $view = strtolower((string) $request->query('view', 'full_year'));
        if (!in_array($view, ['full_year', 'mtd', 'ytd'], true)) $view = 'full_year';

        $month = (int) $request->query('month', $today->format('n'));
        $month = max(1, min(12, $month));

        $budgetGrowth = (float) (config('macro.pl_budget_growth_factor') ?? 1.0);

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "pl_forecast_{$view}_{$year}_{$month}";
            $data = ea_cached($cacheKey, function () use ($client, $year, $month, $view, $budgetGrowth) {
                $svc = new PLForecastService($client);
                return match ($view) {
                    'mtd' => $svc->analyzeMtd($year, $month, $budgetGrowth),
                    'ytd' => $svc->analyzeYtd($year, $budgetGrowth),
                    default => $svc->analyze($year),
                };
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('pl-forecast', [
            'active' => 'pl_forecast',
            'year'   => $year,
            'month'  => $month,
            'view'   => $view,
            'data'   => $data,
            'error'  => $error,
        ]);
    }
}
