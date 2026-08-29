<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard section toggles for the logistics workspace: the per-unit cost
 * card and the KPI Targets & Trends panel off (neither applies to a
 * dossier business), the four logistics summary cards on. Other tenants
 * keep the registry defaults (cards off, panels on) via the fallback in
 * Tenant::dashboardKpiEnabled — no rows are written for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_dashboard_kpis') || !Schema::hasTable('tenants')) return;

        $tenant = DB::table('tenants')->where('slug', 'elimelec')->first();
        if (!$tenant) return;

        $now = now();
        $wanted = [
            'cost_ratio_card'    => false,
            'kpi_targets_trends' => false,
            'logi_file_summary'  => true,
            'logi_closure_rate'  => true,
            'logi_oop_summary'   => true,
            'logi_volume_trend'  => true,
        ];
        foreach ($wanted as $key => $enabled) {
            DB::table('tenant_dashboard_kpis')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'kpi_key' => $key],
                ['enabled' => $enabled, 'enabled_at' => $enabled ? $now : null, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        // Operator data — nothing to reverse safely.
    }
};
