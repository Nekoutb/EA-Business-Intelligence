<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clearance Intelligence replaces the R1–R11 clearance pack.
 *  - every tenant: the retired service (clearance_pack) and modules
 *    (clr_r1 … clr_r11) are removed — their code no longer exists;
 *  - Elimelec: the clearance_intel service and its ten pages are on.
 */
return new class extends Migration
{
    private const PAGES = ['ci_overview', 'ci_activity', 'ci_productivity', 'ci_efficiency', 'ci_profitability',
                           'ci_leakage', 'ci_trends', 'ci_forecast', 'ci_files', 'ci_library'];

    public function up(): void
    {
        if (!Schema::hasTable('tenant_modules') || !Schema::hasTable('tenant_services')) return;

        DB::table('tenant_services')->where('service_key', 'clearance_pack')->delete();
        DB::table('tenant_modules')->where('module_key', 'like', 'clr\_r%')->delete();

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
        $upsert('tenant_services', 'service_key', 'clearance_intel');
        foreach (self::PAGES as $m) $upsert('tenant_modules', 'module_key', $m);
    }

    public function down(): void
    {
        // Module enablement is operator data — nothing to reverse safely.
    }
};
