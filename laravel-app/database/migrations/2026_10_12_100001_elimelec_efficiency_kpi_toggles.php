<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efficiency KPI strip toggles for Elimelec: per-scan cells off, per-file
 * cells on. Per-scan stays available (default on) for every other tenant
 * through Admin → tenant → Dashboard KPIs → Efficiency KPI strip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_dashboard_kpis')) return;
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        foreach (['kpi_rev_per_scan' => false, 'kpi_cost_per_scan' => false,
                  'kpi_rev_per_file' => true,  'kpi_cost_per_file' => true] as $key => $on) {
            DB::table('tenant_dashboard_kpis')->updateOrInsert(
                ['tenant_id' => $tenantId, 'kpi_key' => $key],
                ['enabled' => $on, 'enabled_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void {}
};
