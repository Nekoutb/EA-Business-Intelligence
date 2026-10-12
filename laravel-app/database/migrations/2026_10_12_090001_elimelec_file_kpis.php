<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimelec's dashboard KPIs: Revenue / file and Cost / file replace the
 * per-scan pair. Files = distinct invoice numbers (move_name, e.g.
 * EL26BO1211) on class-70 lines — one invoice posts 2–3 revenue streams.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'KPI_FILE_REF_FIELD'],
            ['value' => 'move_name', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if ($tenantId) DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', 'KPI_FILE_REF_FIELD')->delete();
    }
};
