<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\OperationsService;
use Illuminate\Http\Request;

/**
 * Operations module — container & logistics analytics.
 *
 * Wires the OperationsService analyzer (which is itself in a "schema-
 * pending" state — every KPI helper returns pending=true until the
 * customer's Odoo Logistique schema is mapped via the
 * /admin/tenants/{id}/odoo-probe diagnostic).
 *
 * Pattern matches RevenueController:
 *   - period filter (YTD/MTD) parsed from query string
 *   - per-period cache key in session ("ops_ytd" / "ops_mtd")
 *   - analyzer call wrapped in try/catch so an Odoo failure renders an
 *     error banner instead of a 500
 *
 * Once the analyzer flips off "pending" for each KPI, this controller
 * needs zero changes — the view consumes whatever shape the service
 * returns.
 */
class OperationsController extends Controller
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

        $ops   = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $ops    = ea_cached(
                "ops_{$type}",
                fn () => (new OperationsService($client))->analyze(null, $period)
            );
        } catch (\Throwable $e) {
            // Logistique probe hasn't run yet OR Odoo creds aren't set.
            // Fall back to the all-pending view; banner explains the state.
            $error = $e->getMessage();
        }

        return view('operations', [
            'active'      => 'operations',
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'ops'         => $ops,
            'error'       => $error,
        ]);
    }
}
