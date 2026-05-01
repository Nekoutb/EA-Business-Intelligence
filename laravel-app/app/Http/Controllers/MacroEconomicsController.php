<?php

namespace App\Http\Controllers;

use App\Services\MacroEconomicsService;
use Illuminate\Http\Request;

/**
 * Macro Economics module — six trade-focused indicators (5y actuals +
 * 2y projection) plus the BEAC TIAO rate. Defaults to Cameroon.
 *
 * Country selection via ?country=CMR query param. Service-layer
 * caching is server-wide (Laravel Cache, 24 h on indicators, 6 h on
 * the BEAC scrape); no per-session cache key needed here.
 */
class MacroEconomicsController extends Controller
{
    public function index(Request $request)
    {
        $country = strtoupper((string) $request->query('country', 'CMR'));

        $data  = null;
        $error = null;

        try {
            $data = (new MacroEconomicsService())->analyze($country);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('macro-economics', [
            'active'    => 'macro_economics',
            'country'   => $country,
            'data'      => $data,
            'error'     => $error,
            'countries' => MacroEconomicsService::COUNTRIES,
        ]);
    }
}
