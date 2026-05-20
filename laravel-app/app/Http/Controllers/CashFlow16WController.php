<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\CashFlow16WService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * 16-Week Rolling Cash Flow Forecast page.
 *
 * One controller method (`index`) that orchestrates the build via
 * CashFlow16WService and hands the payload to the matching view.
 * Heavy computation cached for 5 minutes via the standard ea_cached
 * helper — operators can hit "Refresh" to force a recomputation
 * (passes `?refresh=1`).
 */
class CashFlow16WController extends Controller
{
    public function index(Request $request)
    {
        $data = null;
        $error = null;

        // Allow forced refresh and optional ?as_of=YYYY-MM-DD overrides.
        $asOf = $request->query('as_of');
        $cutOff = $asOf && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)
            ? Carbon::parse($asOf)
            : Carbon::now();

        $cacheKey = 'cashflow16w_' . $cutOff->format('Y-m-d');
        if ($request->boolean('refresh')) {
            // Bust cache for this key by appending the minute — keeps
            // ea_cached's 5-min TTL semantics simple.
            $cacheKey .= '_' . now()->format('Hi');
        }

        try {
            $data = ea_cached($cacheKey, function () use ($cutOff) {
                return (new CashFlow16WService(OdooFactory::fromConfig()))->build($cutOff);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('cash-flow-16w', [
            'data'   => $data,
            'error'  => $error,
            'cutOff' => $cutOff->format('Y-m-d'),
            'active' => 'cash_flow_16w',
            'aiPage' => 'cash_flow_16w',
        ]);
    }
}
