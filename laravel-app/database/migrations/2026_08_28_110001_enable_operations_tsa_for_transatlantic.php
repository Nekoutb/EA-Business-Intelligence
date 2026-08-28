<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable Operations_TSA for the tsa workspace and retire the aw_cargo-based
 * Operations module there — that instance has no aw_cargo.* models, so the
 * original page can only error. Other tenants (transatlanticd, which does
 * have aw_cargo) are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenants')) return;

        $tenant = DB::table('tenants')->where('slug', 'tsa')->first();
        if (!$tenant) return;

        $row = DB::table('tenant_modules')
            ->where('tenant_id', $tenant->id)->where('module_key', 'operations_tsa')->first();
        if ($row) {
            DB::table('tenant_modules')->where('id', $row->id)
                ->update(['enabled' => true, 'enabled_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenant->id, 'module_key' => 'operations_tsa',
                'enabled' => true, 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // The aw_cargo-based page cannot work on this instance.
        DB::table('tenant_modules')
            ->where('tenant_id', $tenant->id)->where('module_key', 'operations')
            ->update(['enabled' => false, 'enabled_at' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Module enablement is operator data — nothing to reverse safely.
    }
};
