<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\MonthlyTaxReturnService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Return Reconciliation — standalone page that ties the filed monthly
 * tax return back to the GL ground truth.
 *
 * It reuses the same MonthlyTaxReturnService::analyze() output the
 * /monthly-tax-return page builds (so the figures shown here are the
 * same the operator just declared) and surfaces the 'reconciliation'
 * block from that payload as the page's main content. No new Odoo
 * round-trips: the session cache populated by /monthly-tax-return is
 * shared via the same cache key, so flipping between the two pages
 * for the same month is instant after the first request.
 *
 * Module gate: `module:return_reconciliation` declared on the route in
 * routes/web.php — super-admin can disable per tenant.
 */
class ReturnReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $analysis = null;
        $error    = null;
        $month    = (string) $request->query('month', '');
        if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = '';
        }

        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null;
        // Share the same cache namespace as /monthly-tax-return — same
        // analyze() payload, two routes consuming it. The v2_ prefix
        // version comes from the same place (PR #141) so a single shape
        // change updates both pages atomically.
        $cacheKey = 'monthly_tax_return_v4_' . ($month ?: 'current');

        try {
            $analysis = ea_cached($cacheKey, function () use ($month, $tenant) {
                return (new MonthlyTaxReturnService(OdooFactory::fromConfig()))->analyze($month ?: null, $tenant);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.return-reconciliation', [
            'analysis' => $analysis,
            'error'    => $error,
            'month'    => $month,
            'active'   => 'return_reconciliation',
        ]);
    }
}
