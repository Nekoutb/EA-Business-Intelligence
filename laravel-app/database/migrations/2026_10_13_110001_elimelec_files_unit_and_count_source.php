<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimelec:
 *  - UNIT_NAME=file — Revenue page / dashboard read per file, not per outfit
 *  - FILE_COUNT_SOURCE=clearance — files per month: Productivity settings
 *    before the clearance go-live (Jan–Aug 2026), clearance files from Sep
 *  - PRODUCTIVITY_MANUAL=1 — the Jan–Aug figures already entered were saved
 *    with the switch off and so ignored everywhere
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if (!$tenantId) return;
        foreach (['UNIT_NAME' => 'file', 'FILE_COUNT_SOURCE' => 'clearance', 'PRODUCTIVITY_MANUAL' => '1'] as $k => $v) {
            DB::table('tenant_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => $k],
                ['value' => $v, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void {}
};
