<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ProcurementService;
use Illuminate\Http\Request;

/**
 * Procurement module — PO-lifecycle lens (volume/spend, cycle time, PO
 * compliance). Mirrors OperationsController:
 *   - period filter (YTD/MTD) parsed from query string
 *   - per-period cache key in session ("procurement_ytd" / "procurement_mtd")
 *   - service call wrapped in try/catch so an Odoo failure renders an
 *     error banner instead of a 500
 *
 * Default-disabled in config/modules.php — relies on the Odoo `purchase`
 * module being installed at the tenant. Super admin enables per tenant.
 */
class ProcurementController extends Controller
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

        $procurement = null;
        $error       = null;

        try {
            $client = OdooFactory::fromConfig();
            $procurement = ea_cached(
                "procurement_{$type}",
                fn () => (new ProcurementService($client))->analyze(null, $period)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('procurement', [
            'active'      => 'procurement',
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'procurement' => $procurement,
            'error'       => $error,
        ]);
    }
}
