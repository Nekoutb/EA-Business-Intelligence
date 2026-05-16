<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag tenants as demo sandboxes.
 *
 * Demo tenants are created on the fly when a visitor enters /demo/enter
 * and submits the email-gated form. They live for a configurable TTL
 * (default 2h) and are reaped by a daily cleanup command.
 *
 * Why a flag on `tenants` rather than a separate `demo_tenants` table:
 *   - The existing TenantContext + middleware already resolves the
 *     active tenant from the session — we get isolation for free.
 *   - Existing Tenant relations (settings, modules, services) work
 *     out of the box, so the demo can mirror the real BMS module set.
 *   - Reaper just does `Tenant::where('is_demo', 1)->where('expires_at', '<', now())->delete()`
 *     and SoftDeletes already in place keep an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('plan')->index();
            // Per-tenant expiry — set when the demo sandbox is spawned.
            // Real tenants leave this NULL.
            $table->timestamp('demo_expires_at')->nullable()->after('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['is_demo', 'demo_expires_at']);
        });
    }
};
