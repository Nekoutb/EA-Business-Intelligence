<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BillingService;
use Illuminate\Http\Request;

/**
 * Billing module — invoice-issuance lens (issuance volume, time-to-invoice,
 * credit-note rate). Mirrors OperationsController/RevenueController:
 *   - period filter (YTD/MTD) parsed from query string
 *   - per-period cache key in session ("billing_ytd" / "billing_mtd")
 *   - service call wrapped in try/catch so an Odoo failure renders an
 *     error banner instead of a 500
 */
class BillingController extends Controller
{
    public function index(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) {
            $type = 'ytd';
        }

        // Year/month optional — only ytd/month consume year, only month
        // consumes month. ea_resolve_period clamps to safe ranges.
        $year  = (int) $request->query('year',  $today->format('Y'));
        $month = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [, , $periodLabel] = ea_resolve_period($today, $period);

        $billing = null;
        $error   = null;

        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "billing_{$type}_{$year}_{$month}";
            $billing  = ea_cached(
                $cacheKey,
                fn () => (new BillingService($client))->analyze(null, $period)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('billing', [
            'active'      => 'billing',
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'billing'     => $billing,
            'error'       => $error,
        ]);
    }
}
