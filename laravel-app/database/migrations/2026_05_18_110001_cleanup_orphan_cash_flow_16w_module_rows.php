<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clean up tenant_modules rows for the discontinued
 * `cash_flow_16w` standalone module.
 *
 * Backstory: the previous migration
 * (2026_05_18_100001_enable_cash_flow_16w_for_existing_tenants)
 * inserted a row for every non-demo tenant when the 16-week
 * forecast was its own page at /cash-flow-16w. We've since merged
 * the 16w section into the existing /cashflow-forecast page
 * (under Financial Planning) and removed the standalone module
 * entry from config/modules.php.
 *
 * The orphan rows are harmless — there's no longer a route they
 * gate — but they show up in /admin/tenants/{id}/services as a
 * mystery toggle that does nothing. This migration deletes them.
 *
 * Note: we do NOT touch the parent business_intelligence service
 * row created by the same migration — that service is shared
 * with many other modules (Revenue, Receivables, Treasury, …) so
 * it must stay enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_modules')
            ->where('module_key', 'cash_flow_16w')
            ->delete();
    }

    public function down(): void
    {
        // No restore on rollback — the module no longer exists.
    }
};
