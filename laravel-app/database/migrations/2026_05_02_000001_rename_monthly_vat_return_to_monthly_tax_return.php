<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename `monthly_vat_return` -> `monthly_tax_return` in tenant_modules.
 *
 * The Monthly VAT Return module has been generalised to cover all DGI monthly
 * declarations (TVA, IRPP, TSR, IS, CAC, etc.) and renamed accordingly.
 * This migration updates existing tenant module rows so their feature flag
 * keeps working under the new key.
 *
 * Idempotent: re-runnable; only updates rows that still carry the old key.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('tenant_modules')
            ->where('module_key', 'monthly_vat_return')
            ->update(['module_key' => 'monthly_tax_return']);
    }

    public function down(): void
    {
        DB::table('tenant_modules')
            ->where('module_key', 'monthly_tax_return')
            ->update(['module_key' => 'monthly_vat_return']);
    }
};
