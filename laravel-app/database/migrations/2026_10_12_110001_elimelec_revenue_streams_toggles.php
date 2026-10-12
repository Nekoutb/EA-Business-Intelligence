<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimelec's Revenue page: Cost & Discount Ratios off (no per-unit
 * cost/discount model), revenue streams by nature (class-7 accounts) on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_dashboard_kpis')) return;
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        foreach (['revenue_cost_ratios' => false, 'revenue_streams' => true] as $key => $on) {
            DB::table('tenant_dashboard_kpis')->updateOrInsert(
                ['tenant_id' => $tenantId, 'kpi_key' => $key],
                ['enabled' => $on, 'enabled_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void {}
};
