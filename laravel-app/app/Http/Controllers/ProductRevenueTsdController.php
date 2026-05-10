<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Services\BudgetService;
use App\Services\ProductRevenueService;
use App\Services\ProductVolumeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Product Revenue TSD — Business Insights sub-page.
 *
 * Routes:
 *   GET  /product-revenue-tsd          → index (page)
 *   POST /product-revenue-tsd/budget   → saveBudget (per-product editor submit)
 *
 * Module gate: `module:product_revenue_tsd` (declared on the routes in
 * routes/web.php — same pattern as Revenue / Operations / Treasury).
 *
 * Cache key contains a version suffix (`prt_v1`) so we can bust it
 * cleanly once we add invoice-derived achieved-price feeds.
 */
final class ProductRevenueTsdController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));

        $tenant = $this->resolveTenant();
        $error  = null;
        $analysis = null;

        try {
            $client  = OdooFactory::fromConfig();
            $svc     = new ProductRevenueService(new BudgetService(), new ProductVolumeService($client));
            $cacheKey = 'prt_v1_' . $tenant->id . '_' . $year;
            $analysis = ea_cached($cacheKey, fn() => $svc->analyze($tenant, $year, $today));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('product-revenue-tsd', [
            'analysis'    => $analysis,
            'year'        => $year,
            'error'       => $error,
            'active'      => 'product_revenue_tsd',
            'aiPage'      => 'product_revenue_tsd',
            'periodLabel' => 'Year ' . $year,
        ]);
    }

    /**
     * Persist the per-product budget submitted from Section C of the page.
     *
     * Form encoding: `products[<key>][unit_price]`, `products[<key>][volumes][1..12]`.
     * Only keys that exist in config('budget_products.products') are
     * accepted (others are silently dropped by BudgetService::saveProducts).
     */
    public function saveBudget(Request $request): RedirectResponse
    {
        $tenant = $this->resolveTenant();

        $year     = (int) ($request->input('year') ?: now()->year);
        $products = (array) $request->input('products', []);

        (new BudgetService())->saveProducts($tenant, $year, $products, (int) optional($request->user())->id);

        // Bust the session cache so the page reflects the new budget
        // immediately. ea_cache_clear is tenant-scoped — it only drops
        // this tenant's keys, not other workspaces sharing the session.
        if (function_exists('ea_cache_clear')) {
            ea_cache_clear();
        }

        return redirect()->to(tenant_url('/product-revenue-tsd?year=' . $year))
            ->with('status', 'Product budget saved for ' . $year . '.');
    }

    /**
     * Resolve the current tenant context. Uses the existing global
     * helper that other BI controllers rely on. Falls back to a
     * sensible error if no tenant is in scope.
     */
    private function resolveTenant(): Tenant
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        if (!$tenant instanceof Tenant) {
            abort(404, 'Tenant context unavailable.');
        }
        return $tenant;
    }
}
