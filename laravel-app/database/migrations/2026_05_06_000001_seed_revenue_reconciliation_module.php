<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the revenue_reconciliation module for every tenant that
 * already has the parent `billing` module enabled. Pre-existing
 * tenants get the new sub-feature on by default (which preserves
 * what they see today on /Billing and the Dashboard sankey card);
 * super-admins can flip it off afterwards from /admin/tenants/{id}.
 *
 * Idempotent: skips tenants that already have the row, so re-running
 * is safe. Newly-created tenants get the row through the registry
 * default in config/modules.php (default => true), so we don't need
 * to touch the seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Mirror `billing`'s enablement state — if a tenant has billing
        // off, revenue_reconciliation goes off too. If billing is on,
        // revenue_reconciliation is on (preserves current behavior for
        // every tenant that's actively using the section today).
        $billingRows = DB::table('tenant_modules')
            ->where('module_key', 'billing')
            ->select('tenant_id', 'enabled', 'enabled_by')
            ->get();

        foreach ($billingRows as $row) {
            $exists = DB::table('tenant_modules')
                ->where('tenant_id', $row->tenant_id)
                ->where('module_key', 'revenue_reconciliation')
                ->exists();
            if ($exists) continue;

            DB::table('tenant_modules')->insert([
                'tenant_id'  => $row->tenant_id,
                'module_key' => 'revenue_reconciliation',
                'enabled'    => $row->enabled,
                'enabled_at' => $row->enabled ? $now : null,
                'enabled_by' => $row->enabled_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tenant_modules')
            ->where('module_key', 'revenue_reconciliation')
            ->delete();
    }
};
