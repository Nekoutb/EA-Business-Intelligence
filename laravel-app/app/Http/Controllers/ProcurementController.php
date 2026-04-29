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
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) {
            $type = 'ytd';
        }

        // Year/month optional — only ytd/month consume year, only month
        // consumes month. ea_resolve_period clamps to safe ranges.
        $year  = (int) $request->query('year',  $today->format('Y'));
        $month = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [, , $periodLabel] = ea_resolve_period($today, $period);

        $procurement = null;
        $error       = null;

        try {
            $client = OdooFactory::fromConfig();
            // Cache key embeds year/month so different selections are
            // memoised separately within a session.
            $cacheKey = "procurement_{$type}_{$year}_{$month}";
            $procurement = ea_cached(
                $cacheKey,
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
