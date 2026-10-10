<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn on the Clearance Management service and its eleven reports
 * (clr_r1 … clr_r11) for Elimelec. Every other tenant keeps them off
 * (registry default false); the super admin toggles them per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenant_services')) return;
        $tenant = DB::table('tenants')->where('slug', 'elimelec')->first();
        if (!$tenant) return;

        $upsert = function (string $table, string $col, string $key) use ($tenant) {
            $row = DB::table($table)->where('tenant_id', $tenant->id)->where($col, $key)->first();
            if ($row) {
                DB::table($table)->where('id', $row->id)->update(['enabled' => true, 'enabled_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table($table)->insert(['tenant_id' => $tenant->id, $col => $key, 'enabled' => true,
                    'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        };

        $upsert('tenant_services', 'service_key', 'clearance_pack');
        foreach (range(1, 11) as $n) $upsert('tenant_modules', 'module_key', 'clr_r' . $n);
    }

    public function down(): void
    {
        // Module enablement is operator data — nothing to reverse safely.
    }
};
