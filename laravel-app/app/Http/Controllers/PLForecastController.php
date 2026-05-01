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

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $data = ea_cached(
                "pl_forecast_{$year}",
                fn () => (new PLForecastService($client))->analyze($year)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('pl-forecast', [
            'active' => 'pl_forecast',
            'year'   => $year,
            'data'   => $data,
            'error'  => $error,
        ]);
    }
}
