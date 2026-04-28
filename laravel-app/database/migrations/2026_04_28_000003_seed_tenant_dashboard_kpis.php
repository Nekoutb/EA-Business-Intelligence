<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed tenant_dashboard_kpis for every existing tenant from
 * config/dashboard_kpis.php registry. Idempotent — skips rows that
 * already exist (so re-running deploys is safe).
 *
 * New tenants created after this migration runs get rows inserted
 * automatically by TenantController::store() via the same helper.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $kpis    = config('dashboard_kpis.kpis', []);
        $tenants = DB::table('tenants')->select('id')->get();

        foreach ($tenants as $tenant) {
            foreach ($kpis as $kpi) {
                $key     = $kpi['key']     ?? null;
                $default = $kpi['default'] ?? true;
                if ($key === null) continue;

                $exists = DB::table('tenant_dashboard_kpis')
                    ->where('tenant_id',  $tenant->id)
                    ->where('kpi_key',    $key)
                    ->exists();
                if ($exists) continue;

                DB::table('tenant_dashboard_kpis')->insert([
                    'tenant_id'  => $tenant->id,
                    'kpi_key'    => $key,
                    'enabled'    => (bool) $default,
                    'enabled_at' => $default ? $now : null,
                    'enabled_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Re-runnable; the table itself is dropped by the create migration's down().
    }
};
