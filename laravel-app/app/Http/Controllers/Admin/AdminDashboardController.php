<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusinessInsight;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;

/**
 * Landing page for super admins — high-level platform health.
 */
class AdminDashboardController extends Controller
{
    public function index()
    {
        $totalTenants     = Tenant::count();
        $activeTenants    = Tenant::where('status', '!=', Tenant::STATUS_SUSPENDED)->count();
        $suspendedTenants = Tenant::where('status', Tenant::STATUS_SUSPENDED)->count();
        $tenantUsers      = User::whereNotNull('tenant_id')->count();
        $superAdmins      = User::whereNull('tenant_id')->count();

        // Module activation counts across all tenants (for the heat map).
        $moduleStats = collect(config('modules.modules', []))->map(function ($m) use ($totalTenants) {
            $enabled = TenantModule::where('module_key', $m['key'])->where('enabled', true)->count();
            return [
                'key'    => $m['key'],
                'label'  => $m['label'],
                'group'  => $m['group'] ?? 'analytics',
                'core'   => !empty($m['core']),
                'count'  => $enabled,
                'pct'    => $totalTenants ? (int) round(($enabled / max(1, $totalTenants)) * 100) : 0,
            ];
        })->values();

        $recentAudit = AuditLog::with(['tenant', 'user'])
            ->latest()
            ->limit(20)
            ->get();

        // Latest Business Insight per tenant — drives the dashboard
        // section card and the urgent/watch counts.
        $latestInsights = BusinessInsight::query()
            ->orderByDesc('generated_at')
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->first());
        $insightCounts = [
            'urgent' => $latestInsights->where('priority', 'urgent')->count(),
            'watch'  => $latestInsights->where('priority', 'watch')->count(),
            'good'   => $latestInsights->where('priority', 'good')->count(),
        ];
        // Top 6 most recent — featured on the dashboard.
        $featuredInsights = $latestInsights
            ->sortByDesc(fn ($i) => optional($i->generated_at)->getTimestamp())
            ->take(6)
            ->values();

        return view('admin.dashboard', [
            'totalTenants'     => $totalTenants,
            'activeTenants'    => $activeTenants,
            'suspendedTenants' => $suspendedTenants,
            'tenantUsers'      => $tenantUsers,
            'superAdmins'      => $superAdmins,
            'moduleStats'      => $moduleStats,
            'recentAudit'      => $recentAudit,
            'insightCounts'    => $insightCounts,
            'featuredInsights' => $featuredInsights,
            'active'           => 'dashboard',
        ]);
    }
}
