<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Operations service — container & logistics analytics.
 *
 * Currently renders a proposal/scope page listing the planned KPIs that
 * will land here once the customer's Odoo Logistique schema is mapped
 * (via the one-off /admin/tenants/{id}/odoo-probe diagnostic).
 *
 * When the schema is finalised this controller will:
 *   - fetch from the discovered logistics models (e.g. x_container,
 *     x_container_scan) via OdooFactory
 *   - cache per-period like RevenueController (ea_cached + period key)
 *   - feed an OperationsService analyzer that returns the same shape
 *     RevenueService does (current / previous / period / currency)
 *
 * Until then, the view is a transparent "what's coming" page so the
 * super admin can navigate to the module and see what we're building.
 */
class OperationsController extends Controller
{
    public function index(Request $request)
    {
        // Period selector echoes the dashboard pattern (YTD / MTD).
        // Stored on the view so the page already accepts the filter
        // even though the body is a placeholder — flip the cards on
        // when the analyzer lands without changing this controller.
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd'], true)) {
            $type = 'ytd';
        }
        $period = ['type' => $type];
        [, , $periodLabel] = ea_resolve_period($today, $period);

        return view('operations', [
            'active'      => 'operations',
            'period'      => $period,
            'periodLabel' => $periodLabel,
        ]);
    }
}
