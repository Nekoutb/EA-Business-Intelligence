<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\WorkingCapitalService;
use Illuminate\Http\Request;

/**
 * Working Capital module — eight liquidity / cycle ratios under the
 * Financial Planning service. Snapshot-style: balance-sheet items are
 * "as of" a single date (defaults to today); P&L denominators use a
 * trailing-12-month window ending at that date so DSO/DPO/DIO are
 * always annualised honestly regardless of selection.
 *
 * Period semantics:
 *   ?as_of=YYYY-MM-DD   defaults to today
 *   The balance-sheet snapshot uses date <= as_of
 *   The TTM revenue/COGS uses [as_of − 1y, as_of]
 */
class WorkingCapitalController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $asOfStr = (string) $request->query('as_of', $today->format('Y-m-d'));
        try {
            $asOf = new \DateTimeImmutable($asOfStr);
        } catch (\Throwable $e) {
            $asOf = $today;
        }
        // Don't allow future as-of dates — clamps user pranks / typos.
        if ($asOf > $today) $asOf = $today;

        $wc    = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = 'working_capital_' . $asOf->format('Y_m_d');
            $wc = ea_cached(
                $cacheKey,
                fn () => (new WorkingCapitalService($client))->analyze($asOf)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('working-capital', [
            'active' => 'working_capital',
            'wc'     => $wc,
            'asOf'   => $asOf->format('Y-m-d'),
            'error'  => $error,
        ]);
    }
}
