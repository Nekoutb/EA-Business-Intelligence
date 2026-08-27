<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable the OOP (Out of Pocket Expense) Compliance module for
 * logistics-sector tenants. Today: elimelec. Default-off elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenants')) return;

        $tenant = DB::table('tenants')->where('slug', 'elimelec')->first();
        if (!$tenant) return;

        $row = DB::table('tenant_modules')
            ->where('tenant_id', $tenant->id)->where('module_key', 'logistics_oop')->first();
        if ($row) {
            DB::table('tenant_modules')->where('id', $row->id)
                ->update(['enabled' => true, 'enabled_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenant->id, 'module_key' => 'logistics_oop',
                'enabled' => true, 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Module enablement is operator data — nothing to reverse safely.
    }
};
