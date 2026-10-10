<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimelec's file history (3,644 files 2011 → 26 Aug 2026, advances,
 * validations, invoices) was synced from Teese under tenant id 23 and is
 * still in the warehouse; the workspace now runs as tenant 25 on Odoo, so
 * its five logistics pages read an empty dataset. Point them at the Teese
 * history until the files themselves live in Odoo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        if (!DB::table('wh_fact_dossier')->where('tenant_id', 23)->exists()) return;
        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'LOGISTICS_DATA_TENANT'],
            ['value' => '23', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if ($tenantId) DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', 'LOGISTICS_DATA_TENANT')->delete();
    }
};
