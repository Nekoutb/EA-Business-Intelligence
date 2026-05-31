<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessInsight;
use App\Models\Tenant;
use App\Services\BusinessInsightsService;
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
