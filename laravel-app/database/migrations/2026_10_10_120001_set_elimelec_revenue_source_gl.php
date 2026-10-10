<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimelec's fees reach Odoo as journal entries on 706xxx (migrated from
 * Teese), not as customer invoices: the invoice revenue source read 0 while
 * the P&L showed ~276 M XAF. Switch the tenant to the ledger source, which
 * also turns on the GL open-item ageing for receivables and payables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'REVENUE_SOURCE'],
            ['value' => 'gl', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if ($tenantId) DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', 'REVENUE_SOURCE')->update(['value' => 'invoice']);
    }
};
