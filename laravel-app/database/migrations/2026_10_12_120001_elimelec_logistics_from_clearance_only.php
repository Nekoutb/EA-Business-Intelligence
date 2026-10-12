<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimelec: ignore the Teese history, read only Odoo's clearance application.
 *  - drop LOGISTICS_DATA_TENANT (pointed the pages at Teese tenant 23)
 *  - LOGISTICS_SOURCE=clearance → Files, OOP Compliance, Volumes and the
 *    dashboard logistics cards come from logistics.file / logistics.expense
 *  - Workflow and Reports·Logistics only exist on Teese data (validation
 *    circuits, Teese invoices) → switched off; Clearance R5 (turnaround)
 *    and R6/R7 (profitability, client portfolio) cover them from Odoo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;

        DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', 'LOGISTICS_DATA_TENANT')->delete();
        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'LOGISTICS_SOURCE'],
            ['value' => 'clearance', 'updated_at' => now(), 'created_at' => now()]
        );

        if (Schema::hasTable('tenant_modules')) {
            foreach (['logistics_workflow', 'reports_logistics'] as $m) {
                DB::table('tenant_modules')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'module_key' => $m],
                    ['enabled' => false, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void {}
};
