<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Productivity settings — manual monthly employee count and number of
 * files (optionally split by file nature), entered by the platform
 * administrator. When the tenant's PRODUCTIVITY_MANUAL setting is on, they
 * replace the system counts in every per-employee / per-file computation.
 * Also turns the settings page on for Elimelec.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_productivity_months')) {
            Schema::create('tenant_productivity_months', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedSmallInteger('year');
                $t->unsignedTinyInteger('month');
                $t->unsignedInteger('employees')->nullable();
                $t->unsignedInteger('files')->nullable();
                $t->json('natures')->nullable();          // {"Import": 40, "Export": 12}
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'year', 'month']);
            });
        }

        $tenantId = DB::table('tenants')->where('slug', 'elimelec')->value('id');
        if ($tenantId && Schema::hasTable('tenant_modules')) {
            DB::table('tenant_modules')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module_key' => 'hr_productivity_settings'],
                ['enabled' => true, 'enabled_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_productivity_months');
    }
};
