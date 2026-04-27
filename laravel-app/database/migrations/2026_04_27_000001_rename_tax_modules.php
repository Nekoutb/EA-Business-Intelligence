<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant module taxonomy rename (2026-04-27).
 *
 *   vat_compliance  -> tax_dashboard       (the dashboard page was
 *                                            relabelled twice: VAT
 *                                            Compliance -> Tax Console
 *                                            -> Tax Dashboard. Now also
 *                                            renamed at the key level.)
 *   tax_calendar    -> (removed)            (calendar moved into the
 *                                            Tax Dashboard view)
 *
 * After this migration, existing tenants whose `tenant_modules` rows
 * referenced 'vat_compliance' will instead reference 'tax_dashboard',
 * preserving their enabled/disabled state. Rows referencing
 * 'tax_calendar' are deleted because the module no longer exists.
 *
 * The freed key `vat_compliance` is now reused by a brand-new module
 * (label "VAT Compliance") via config/modules.php; existing tenants
 * pick it up on next sidebar render once the registry is consulted.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('tenant_modules')
            ->where('module_key', 'vat_compliance')
            ->update(['module_key' => 'tax_dashboard']);

        DB::table('tenant_modules')
            ->where('module_key', 'tax_calendar')
            ->delete();
    }

    public function down(): void
    {
        DB::table('tenant_modules')
            ->where('module_key', 'tax_dashboard')
            ->update(['module_key' => 'vat_compliance']);

        // Calendar rows cannot be safely restored (we don't know the
        // historical enabled state); the down() leaves them absent.
    }
};
