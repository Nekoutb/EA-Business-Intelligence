<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the monthly_vat_return module for every tenant that already
 * has vat_invoice_compliance enabled — these tenants are in the VAT-
 * compliant flow and should automatically get the new monthly return
 * dashboard. Idempotent: skips tenants that already have the module
 * row (newly-created tenants get it automatically through the registry
 * + industry bundle, so we don't double-insert).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $tenantsWithVat = DB::table('tenant_modules')
            ->where('module_key', 'vat_invoice_compliance')
            ->select('tenant_id', 'enabled', 'enabled_by')
            ->get();

        foreach ($tenantsWithVat as $row) {
            $exists = DB::table('tenant_modules')
                ->where('tenant_id', $row->tenant_id)
                ->where('module_key', 'monthly_vat_return')
                ->exists();
            if ($exists) continue;

            DB::table('tenant_modules')->insert([
                'tenant_id'  => $row->tenant_id,
                'module_key' => 'monthly_vat_return',
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
            ->where('module_key', 'monthly_vat_return')
            ->delete();
    }
};
