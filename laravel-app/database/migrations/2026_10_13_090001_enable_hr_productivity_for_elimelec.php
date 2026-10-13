<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Human Resources service + its new Productivity page, on for Elimelec. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenant_services')) return;
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        DB::table('tenant_services')->updateOrInsert(
            ['tenant_id' => $tenantId, 'service_key' => 'human_resources'],
            ['enabled' => true, 'enabled_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('tenant_modules')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module_key' => 'hr_productivity'],
            ['enabled' => true, 'enabled_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void {}
};
