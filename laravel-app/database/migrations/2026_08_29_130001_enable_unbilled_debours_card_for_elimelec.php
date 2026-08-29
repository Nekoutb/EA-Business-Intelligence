<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Switch the Unbilled débours dashboard card on for the logistics workspace. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_dashboard_kpis') || !Schema::hasTable('tenants')) return;
        $tenant = DB::table('tenants')->where('slug', 'elimelec')->first();
        if (!$tenant) return;
        $now = now();
        DB::table('tenant_dashboard_kpis')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'kpi_key' => 'logi_unbilled_debours'],
            ['enabled' => true, 'enabled_at' => $now, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    public function down(): void {}
};
