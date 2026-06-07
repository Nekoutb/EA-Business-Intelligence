<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessInsight;
use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Services\BusinessInsightsService;
use App\Services\DataInventoryService;
use App\Services\RevenueAnalysisService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Super-admin Business Insights surface.
 *
 *   GET  /admin/business-insights              — index: card per tenant
 *   GET  /admin/business-insights/{tenant}     — full markdown body for one tenant
 *   POST /admin/business-insights/{tenant}/refresh — re-run generation now (admin click)
 *   POST /admin/business-insights/run-all      — fleet-wide on-demand generation
 *
 * Read-only on /admin (gated by admin.auth on the route group).
 */
class BusinessInsightsController extends Controller
{
    public function index()
    {
        // Real (non-demo) tenants only — visitor demo sandboxes pile
        // up dozens/day during prospecting and would otherwise drown
        // out the actual customers in this view.
        $tenants = Tenant::real()->orderBy('name')->get();
        $latestByTenant = BusinessInsight::query()
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->orderByDesc('generated_at')
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->first());

        return view('admin.business-insights.index', [
            'tenants' => $tenants,
            'latest'  => $latestByTenant,
            'active'  => 'business_insights',
        ]);
    }

    public function show(int $tenantId)
    {
        // Block direct navigation to demo tenants — they shouldn't
        // surface here at all. If someone bookmarks an old URL or
        // pastes one in, treat it as "not in this view".
        $tenant = Tenant::real()->where('id', $tenantId)->firstOrFail();
        $latest = BusinessInsight::latestForTenant($tenant->id);
        $history = BusinessInsight::where('tenant_id', $tenant->id)
            ->orderByDesc('generated_at')
            ->limit(14)
            ->get();

        return view('admin.business-insights.show', [
            'tenant'  => $tenant,
            'latest'  => $latest,
            'history' => $history,
            'active'  => 'business_insights',
        ]);
    }

    public function refresh(Request $request, int $tenantId, BusinessInsightsService $svc)
    {
        // Same demo block as show() — don't waste an LLM call on
        // throwaway demo data even if someone clicks the button.
        $tenant = Tenant::real()->where('id', $tenantId)->firstOrFail();
        try {
            $row = $svc->generate($tenant, true);
            return redirect("/admin/business-insights/{$tenant->id}")->with('flash', [
                'type' => 'success',
                'msg'  => 'Insight regenerated · status=' . $row->status . ' priority=' . $row->priority,
            ]);
        } catch (\Throwable $e) {
            return redirect("/admin/business-insights/{$tenant->id}")->with('flash', [
                'type' => 'error',
                'msg'  => 'Generation failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Data inventory — what financial data does this tenant have in
     * Odoo, broken down by domain (revenue, AR, AP, treasury, …).
     * Foundation for the on-demand reports: shows the operator
     * exactly which domains have enough data to support each report
     * type.
     */
    public function inventory(int $tenantId, DataInventoryService $svc = null)
    {
        $tenant = Tenant::real()->where('id', $tenantId)->firstOrFail();

        // Bind the tenant context so OdooFactory pulls the tenant's
        // credentials, then invoke the service.
        app(TenantContext::class)->set($tenant);
        $inventory = null;
        $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $service = new DataInventoryService($client);
            $inventory = ea_cached(
                "data_inventory_{$tenant->id}_" . now()->format('Y-m-d_H'),
                fn () => $service->inventory()
            );
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'bi_data_inventory', 'Could not build the data inventory. Check the tenant\'s Odoo connection in Settings.');
        }

        return view('admin.business-insights.inventory', [
            'tenant'    => $tenant,
            'inventory' => $inventory,
            'error'     => $error,
            'active'    => 'business_insights',
        ]);
    }

    /**
     * Revenue analysis — the first on-demand management report.
     * Deterministic + reproducible: same input data → same numbers.
     * No LLM in the loop, so auditable.
     */
    public function revenueReport(Request $request, int $tenantId)
    {
        $tenant = Tenant::real()->where('id', $tenantId)->firstOrFail();
        $year   = (int) $request->query('year', date('Y'));

        app(TenantContext::class)->set($tenant);
        $report = null;
        $error  = null;
        try {
            $client = OdooFactory::fromConfig();
            $service = new RevenueAnalysisService($client);
            $report = ea_cached(
                "revenue_report_{$tenant->id}_{$year}_" . now()->format('Y-m-d_H'),
                fn () => $service->analyze($year)
            );
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'bi_revenue_report', 'Could not generate the revenue report. Check the tenant\'s Odoo connection in Settings.');
        }

        return view('admin.business-insights.revenue-report', [
            'tenant' => $tenant,
            'report' => $report,
            'error'  => $error,
            'year'   => $year,
            'active' => 'business_insights',
        ]);
    }

    public function runAll(Request $request, BusinessInsightsService $svc)
    {
        // Real tenants only — same reasoning as index(). Generating
        // insights for visitor demo sandboxes burns LLM tokens for
        // throwaway analyses on synthetic data.
        $tenants = Tenant::real()->orderBy('id')->get();
        $ok = 0; $partial = 0; $failed = 0; $skipped = 0;
        foreach ($tenants as $t) {
            try {
                $r = $svc->generate($t, true);
                if      ($r->status === 'ok')      $ok++;
                elseif  ($r->status === 'partial') $partial++;
                elseif  ($r->status === 'skipped') $skipped++;
                else                                $failed++;
            } catch (\Throwable $e) { $failed++; }
        }
        return redirect('/admin/business-insights')->with('flash', [
            'type' => $failed === 0 ? 'success' : 'error',
            'msg'  => "Fleet-wide regeneration done: ok={$ok} · partial={$partial} · skipped={$skipped} · failed={$failed}",
        ]);
    }
}
