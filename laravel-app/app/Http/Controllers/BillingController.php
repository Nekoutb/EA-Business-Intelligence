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
        if (!in_array($type, ['ytd', 'mtd'], true)) {
            $type = 'ytd';
        }
        $period = ['type' => $type];
        [, , $periodLabel] = ea_resolve_period($today, $period);

        $billing = null;
        $error   = null;

        try {
            $client = OdooFactory::fromConfig();
            $billing = ea_cached(
                "billing_{$type}",
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
