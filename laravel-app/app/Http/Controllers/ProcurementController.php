<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BudgetService;
use App\Services\ProcurementDashboardService;
use App\Services\ProcurementService;
use App\Tenancy\TenantContext;
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
 *
 * Also hosts the Procurement Control Dashboard (?view=dashboard) — the
 * one-page governance view rendered through this route because a dedicated
 * route would 404 behind this host's frozen route cache.
 */
class ProcurementController extends Controller
{
    /** Roles allowed to see/edit the dashboard's target-setup panel. */
    private const ADMIN_ROLES = ['super_admin', 'tenant_admin', 'owner'];

    public function index(Request $request)
    {
        // Procurement Control Dashboard — hosted on this cached route.
        if ($request->query('view') === 'dashboard') {
            return $this->dashboard($request);
        }

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

        // Monthly KPI-evolution graphs (same eight indicators as the
        // Procurement Dashboard, Jan → last closed month). Cached per month;
        // the manual emergency count is injected fresh so an admin edit on
        // the dashboard shows here immediately.
        $trends = null;
        try {
            $mKey = (new \DateTimeImmutable('first day of this month'))->modify('-1 day')->format('Y-m');
            $trends = ea_cached("proc_trends_v1_{$mKey}",
                fn () => (new ProcurementDashboardService(OdooFactory::fromConfig()))->monthlyTrends());
            $ctx = app(TenantContext::class);
            $emergency = $ctx->has()
                ? (new BudgetService())->loadProcurementDash($ctx->tenant()->id, (int) (new \DateTimeImmutable('today'))->format('Y'))['emergency']
                : 0;
            if (is_array($trends) && !empty($trends['labels'])) {
                $trends['series']['emergency'] = array_fill(0, count($trends['labels']), (float) $emergency);
            }
        } catch (\Throwable $e) {
            $trends = null;
        }

        return view('procurement', [
            'active'      => 'procurement',
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'procurement' => $procurement,
            'trends'      => $trends,
            'error'       => $error,
        ]);
    }

    /**
     * Procurement Control Dashboard — 8 KPIs vs admin-set targets, cumulative
     * YTD as at the last closed month-end. Rendered through procurement.blade
     * behind the dashMode flag (this host cannot compile brand-new views).
     */
    private function dashboard(Request $request)
    {
        $asAt = ProcurementDashboardService::asAtDate();
        $year = (int) $asAt->format('Y');

        $dash = null; $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $dash = ea_cached('proc_dash_v1_' . $asAt->format('Y-m-d'), function () use ($client, $asAt) {
                return (new ProcurementDashboardService($client))->analyze($asAt);
            });
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'procurement.dashboard', 'Could not load procurement data from Odoo. Check the connection in Settings.');
        }

        // Admin-set targets + this year's manual emergency count.
        $targets = BudgetService::PROC_DASH_DEFAULT_TARGETS;
        $emergency = 0;
        try {
            $ctx = app(TenantContext::class);
            if ($ctx->has()) {
                $stored = (new BudgetService())->loadProcurementDash($ctx->tenant()->id, $year);
                $targets   = $stored['targets'];
                $emergency = $stored['emergency'];
            }
        } catch (\Throwable $e) { /* defaults stand */ }

        // auth() rather than $request->user(): the guard resolves the logged-in
        // user regardless of how the request object was constructed.
        $role = (string) optional(auth()->user())->role;

        return view('procurement', [
            'dashMode'    => true,
            'active'      => 'procurement_dashboard',
            'dash'        => $dash,
            'dashError'   => $error,
            'targets'     => $targets,
            'emergency'   => $emergency,
            'isAdmin'     => in_array($role, self::ADMIN_ROLES, true),
            'asAt'        => $asAt->format('Y-m-d'),
            'nextRefresh' => $asAt->modify('+7 days')->format('Y-m-d'),
            // Keep the normal branch's variables defined for safety.
            'period'      => ['type' => 'ytd'],
            'periodLabel' => 'YTD',
            'procurement' => null,
            'error'       => null,
        ]);
    }

    /**
     * Persist the dashboard targets (operator + value per KPI) and the
     * year's emergency-procedures count. Reached via the cached POST /sync
     * route on the _intent=proc_targets marker (a dedicated POST route would
     * 404 behind the stale route cache). Admin-only.
     */
    public function saveDashboardTargets(Request $request)
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->has()) abort(404);
        $role = (string) optional(auth()->user())->role;
        if (!in_array($role, self::ADMIN_ROLES, true)) {
            abort(403, 'Only administrators can set procurement targets.');
        }

        $v = $request->validate([
            'targets'           => ['nullable', 'array'],
            'targets.*.op'      => ['nullable', 'in:lt,lte,gt,gte'],
            'targets.*.value'   => ['nullable', 'numeric', 'min:0'],
            'emergency_count'   => ['nullable', 'integer', 'min:0'],
        ]);

        $year = (int) ProcurementDashboardService::asAtDate()->format('Y');
        (new BudgetService())->saveProcurementDash(
            $ctx->tenant(),
            $year,
            (array) ($v['targets'] ?? []),
            array_key_exists('emergency_count', $v) && $v['emergency_count'] !== null ? (int) $v['emergency_count'] : null,
            optional(auth()->user())->id
        );

        return redirect()->to(tenant_url('/procurement?view=dashboard'))->with('flash', [
            'type' => 'success',
            'msg'  => 'Procurement targets saved.',
        ]);
    }
}
