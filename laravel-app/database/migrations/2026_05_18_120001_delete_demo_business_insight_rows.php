<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time cleanup: delete all BusinessInsight rows attached to
 * demo (is_demo=true) tenants so the admin /admin/business-insights
 * index page immediately reflects the "real tenants only" filter,
 * without waiting for the daily reaper to pull the underlying
 * demo tenants.
 *
 * Why a migration and not a tinker script: it's auditable, runs
 * once per environment automatically via `php artisan migrate`,
 * and the migration record proves to the next operator that the
 * cleanup happened (no need to wonder whether old data is still
 * lingering).
 *
 * Guarded by Schema::hasColumn — silently no-ops on environments
 * where the is_demo column doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tenants', 'is_demo')) {
            return;
        }

        $demoTenantIds = DB::table('tenants')
            ->where('is_demo', true)
            ->pluck('id');
        if ($demoTenantIds->isEmpty()) {
            return;
        }

        $deleted = DB::table('business_insights')
            ->whereIn('tenant_id', $demoTenantIds)
            ->delete();

        // Stamp the audit_logs table if it exists so the next
        // operator can trace why these rows went away.
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'target'     => 'business_insights',
                'action'     => 'migration.cleanup_demo_insights',
                'payload'    => json_encode([
                    'deleted_rows'    => (int) $deleted,
                    'demo_tenant_ids' => $demoTenantIds->all(),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No restore on rollback — the rows were demo data, not
        // production analyses worth preserving.
    }
};
