<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sectors of activity (2026-08): assign the three live tenants to their
 * sector and apply the sector's dashboard-KPI defaults.
 *
 *   kissywears / kwr  → fashion            (outfit metrics stay on)
 *   transatlanticd    → container_scanning (their Operations page is the
 *                                           sector's operations module)
 *   elimelec          → logistics_services (dossier-based operations)
 *
 * Runs before db:seed on a deploy, so the sector industries are created
 * here if absent (the seeder then updateOrCreates over them — same keys,
 * no duplicates). Module rows of existing tenants are deliberately left
 * untouched: live configurations must not change under anyone's feet.
 * Only the outfit-KPI cards are defaulted OFF for the two non-fashion
 * tenants, and only where no explicit per-tenant row exists yet.
 */
return new class extends Migration
{
    private const SECTORS = [
        // slug patterns → industry key
        'kwr'            => 'fashion',
        'kissywears'     => 'fashion',
        'transatlanticd' => 'container_scanning',
        'elimelec'       => 'logistics_services',
    ];

    private const OUTFIT_KPIS = ['rpo', 'opd', 'dpot', 'cpo', 'top_profitable_products'];

    public function up(): void
    {
        if (!Schema::hasTable('industries') || !Schema::hasTable('tenants')) return;

        $defs = collect(config('industries.industries', []))
            ->whereIn('key', ['fashion', 'logistics_services', 'container_scanning']);
        foreach ($defs as $def) {
            DB::table('industries')->updateOrInsert(
                ['key' => $def['key']],
                [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                    'icon'        => $def['icon'],
                    'sort_order'  => $def['sort_order'],
                    'active'      => true,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }

        $ids = DB::table('industries')->whereIn('key', array_values(self::SECTORS))
            ->pluck('id', 'key');

        foreach (self::SECTORS as $slug => $industryKey) {
            $tenant = DB::table('tenants')->where('slug', $slug)->first();
            if (!$tenant || !isset($ids[$industryKey])) continue;

            DB::table('tenants')->where('id', $tenant->id)
                ->update(['industry_id' => $ids[$industryKey]]);

            // Non-fashion sectors: outfit KPI cards default OFF — but never
            // override a row an admin already set by hand.
            if ($industryKey !== 'fashion' && Schema::hasTable('tenant_dashboard_kpis')) {
                foreach (self::OUTFIT_KPIS as $kpi) {
                    $exists = DB::table('tenant_dashboard_kpis')
                        ->where('tenant_id', $tenant->id)->where('kpi_key', $kpi)->exists();
                    if (!$exists) {
                        DB::table('tenant_dashboard_kpis')->insert([
                            'tenant_id'  => $tenant->id,
                            'kpi_key'    => $kpi,
                            'enabled'    => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Sector assignment is data, not schema — nothing to reverse safely.
    }
};
