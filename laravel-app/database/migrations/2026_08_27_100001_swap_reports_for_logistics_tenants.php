<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics workspaces get Reports_Logistics (fee revenue per customer)
 * instead of the generic Reports page, which was built around daily
 * sales/inventory summaries for the fashion tenant. Today: elimelec.
 * Other tenants are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenants')) return;

        $tenant = DB::table('tenants')->where('slug', 'elimelec')->first();
        if (!$tenant) return;

        // Enable the logistics report.
        $row = DB::table('tenant_modules')
            ->where('tenant_id', $tenant->id)->where('module_key', 'reports_logistics')->first();
        if ($row) {
            DB::table('tenant_modules')->where('id', $row->id)
                ->update(['enabled' => true, 'enabled_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenant->id, 'module_key' => 'reports_logistics',
                'enabled' => true, 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Retire the generic Reports page for this workspace.
        DB::table('tenant_modules')
            ->where('tenant_id', $tenant->id)->where('module_key', 'reports')
            ->update(['enabled' => false, 'enabled_at' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Module enablement is operator data — nothing to reverse safely.
    }
};
