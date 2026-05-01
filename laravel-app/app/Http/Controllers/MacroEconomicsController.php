<?php

namespace App\Http\Controllers;

use App\Services\MacroEconomicsService;
use Illuminate\Http\Request;

/**
 * Macro Economics module — six trade-focused indicators (5y actuals +
 * 2y projection) plus the BEAC TIAO rate. Cameroon-only for this
 * tenant; the country picker has been removed at the user's request.
 *
 * Underlying service still supports any ISO3 code via analyze($cc),
 * so future tenants on other CEMAC countries can be enabled by
 * passing a different code here without touching the service.
 */
class MacroEconomicsController extends Controller
{
    /** ISO 3166-1 alpha-3 of the only country surfaced on this page. */
    private const COUNTRY = 'CMR';

    public function index(Request $request)
    {
        $data  = null;
        $error = null;

        try {
            $data = (new MacroEconomicsService())->analyze(self::COUNTRY);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('macro-economics', [
            'active' => 'macro_economics',
            'data'   => $data,
            'error'  => $error,
        ]);
    }
}
