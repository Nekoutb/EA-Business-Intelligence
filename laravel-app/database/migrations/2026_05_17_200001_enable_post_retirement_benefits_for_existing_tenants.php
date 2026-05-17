<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable the post_retirement_benefits module for every existing
 * production (non-demo) tenant.
 *
 * Backstory: the module landed live in PR #154 (Phase 1) with
 * default=false in config/modules.php. New tenants didn't auto-
 * enable it; existing tenants had no enabling row either, so
 * navigating to /post-retirement-benefits hit the
 * EnsureModuleEnabled middleware and returned a 403 (rendered as
 * 404 on production due to a stale route cache + the generic
 * catch-all error page).
 *
 * This migration:
 *   - inserts a tenant_modules row for each real tenant where
 *     no row exists yet
 *   - sets enabled=true
 *   - skips demo tenants (is_demo=true) — visitor demo
 *     sandboxes get the same defaults as new BMS tenants on
 *     spawn, this migration is only for pre-existing real ones
 *
 * Companion to setting default=true in config/modules.php so
 * any tenant created after this point also gets the module on
 * by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Find every real (non-demo) tenant that does NOT yet have
        // a tenant_modules row for post_retirement_benefits.
        $tenants = DB::table('tenants')
            ->where(function ($q) {
                $q->where('is_demo', false)->orWhereNull('is_demo');
            })
            ->whereNotIn('id', function ($q) {
                $q->select('tenant_id')
                  ->from('tenant_modules')
                  ->where('module_key', 'post_retirement_benefits');
            })
            ->select('id')
            ->get();

        $now = now();
        $rows = [];
        foreach ($tenants as $t) {
            $rows[] = [
                'tenant_id'  => $t->id,
                'module_key' => 'post_retirement_benefits',
                'enabled'    => true,
                'enabled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (!empty($rows)) {
            // Chunk to stay within SQL placeholder limits even on
            // very large tenant populations.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('tenant_modules')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        // Roll back ONLY rows this migration created (where
        // enabled_at = the timestamp written above is impossible to
        // pin down reliably). Safest is to delete enabled rows
        // for the module across non-demo tenants — operators can
        // re-enable manually if needed.
        DB::table('tenant_modules')
            ->where('module_key', 'post_retirement_benefits')
            ->whereIn('tenant_id', function ($q) {
                $q->select('id')
                  ->from('tenants')
                  ->where(function ($w) {
                      $w->where('is_demo', false)->orWhereNull('is_demo');
                  });
            })
            ->delete();
    }
};
