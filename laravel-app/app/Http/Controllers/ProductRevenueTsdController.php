<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Services\BudgetService;
use App\Services\GLRevenueService;
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
 * Cache key carries a version suffix so we can bust it cleanly across
 * structural changes:
 *   - v1 → v2: ProductVolumeService switched from read_group to
 *     delegating to OperationsService::scanRevenueBreakdown
 *   - v2 → v3: container revenue now sourced from class 706 GL accounts
 *     (GLRevenueService) instead of scan_count × tariff (which was 0)
 *   - v4 → v5: analyze() now also returns class70_non_invoice (lines
 *     posted to class 70 outside customer invoices/refunds), so the
 *     view can render the reconciliation-gap diagnostic
 *   - v5 → v6: analyze() now respects the master period filter
 *     (mtd / ytd / month) — budget and actuals slice to the chosen
 *     period instead of always YTD-through-today. Cache key carries
 *     the period suffix so MTD and YTD don't share a bucket.
 */
final class ProductRevenueTsdController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));

        // Master period filter — same vocabulary as Dashboard / Revenue.
        //   ytd   → cumulative through today's month (default)
        //   mtd   → current month only
        //   month → user-picked month only
        //   yoy   → frozen (variance-vs-prior-year doesn't apply to a
        //           per-product P&V breakdown; the button is disabled
        //           via @section('mfilter-frozen', 'yoy') in the view).
        //           If a legacy session still sends it, fall back to YTD.
        $masterPeriod = strtolower((string) $request->query('period', 'ytd'));
        $periodType   = match ($masterPeriod) {
            'mtd'   => 'mtd',
            'month' => 'month',
            default => 'ytd',
        };
        $monthFilter = max(1, min(12, (int) $request->query('month', (int) $today->format('n'))));

        $tenant = $this->resolveTenant();
        $error  = null;
        $analysis = null;

        try {
            $client  = OdooFactory::fromConfig();
            $svc      = new ProductRevenueService(
                new BudgetService(),
                new ProductVolumeService($client),
                new GLRevenueService($client),
            );
            $cacheKey = 'prt_v6_' . $tenant->id . '_' . $year . '_' . $periodType . '_' . $monthFilter;
            $analysis = ea_cached(
                $cacheKey,
                fn() => $svc->analyze($tenant, $year, $today, $periodType, $monthFilter)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('product-revenue-tsd', [
            'analysis'    => $analysis,
            'year'        => $year,
            'periodType'  => $periodType,
            'monthFilter' => $monthFilter,
            'error'       => $error,
            'active'      => 'product_revenue_tsd',
            'aiPage'      => 'product_revenue_tsd',
            'periodLabel' => $this->periodLabel($periodType, $year, $monthFilter, $today),
        ]);
    }

    /**
     * Human-readable label for the active period — surfaced in the page
     * subtitle so the user always sees what slice the figures cover.
     */
    private function periodLabel(string $periodType, int $year, int $monthFilter, \DateTimeImmutable $today): string
    {
        $monthName = static function (int $m): string {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-01', 2000, max(1, min(12, $m)))))->format('F');
        };

        $todayYear = (int) $today->format('Y');

        return match ($periodType) {
            'mtd'   => 'Month-to-date · ' . $monthName((int) $today->format('n')) . ' ' . $year,
            'month' => $monthName($monthFilter) . ' ' . $year,
            default => $year >= $todayYear
                ? 'Year-to-date ' . $year . ' · through ' . $monthName((int) $today->format('n'))
                : 'Year ' . $year,
        };
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
