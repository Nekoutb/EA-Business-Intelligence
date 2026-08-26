<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable the Logistics Operations modules (+ profitability, which hosts
 * the sector's unit economics) for logistics-sector tenants — today:
 * elimelec. Deliberately DOES NOT touch other tenants: the modules
 * default to disabled, so Transatlantic and Kissywears simply never see
 * them (and the super admin can toggle per tenant at any time).
 */
return new class extends Migration
{
    private const MODULES = ['logistics_dossiers', 'logistics_workflow', 'logistics_volumes', 'profitability'];

    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenants')) return;

        $tenant = DB::table('tenants')->where('slug', 'elimelec')->first();
        if (!$tenant) return;

        foreach (self::MODULES as $key) {
            $exists = DB::table('tenant_modules')
                ->where('tenant_id', $tenant->id)->where('module_key', $key)->first();
            if ($exists) {
                if (!$exists->enabled) {
                    DB::table('tenant_modules')->where('id', $exists->id)
                        ->update(['enabled' => true, 'enabled_at' => now(), 'updated_at' => now()]);
                }
            } else {
                DB::table('tenant_modules')->insert([
                    'tenant_id'  => $tenant->id,
                    'module_key' => $key,
                    'enabled'    => true,
                    'enabled_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Module enablement is operator data — nothing to reverse safely.
    }
};
