<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the new `run_rate` (Annualised Run-Rate) Dashboard KPI for
 * every existing tenant. Defaults to enabled per the registry. Uses
 * the registry default rather than hard-coding TRUE so a future
 * registry change (default => false) is honoured by next deploy.
 *
 * Idempotent — skips tenants that already have a row for this key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $kpis = collect(config('dashboard_kpis.kpis', []));
        $entry = $kpis->firstWhere('key', 'run_rate');
        $default = (bool) ($entry['default'] ?? true);

        $tenants = DB::table('tenants')->select('id')->get();
        foreach ($tenants as $tenant) {
            $exists = DB::table('tenant_dashboard_kpis')
                ->where('tenant_id', $tenant->id)
                ->where('kpi_key', 'run_rate')
                ->exists();
            if ($exists) continue;

            DB::table('tenant_dashboard_kpis')->insert([
                'tenant_id'  => $tenant->id,
                'kpi_key'    => 'run_rate',
                'enabled'    => $default,
                'enabled_at' => $default ? $now : null,
                'enabled_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tenant_dashboard_kpis')
            ->where('kpi_key', 'run_rate')
            ->delete();
    }
};
