<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user permission overrides.
 *
 * The `role` column on users gives a coarse default (tenant_admin /
 * manager / analyst / viewer). The `permissions` JSON column lets the
 * tenant admin opt-in or opt-out specific services / modules / KPIs
 * for an individual user, on top of the role default.
 *
 * Shape (all keys optional — absence = role default applies):
 *   {
 *     "services":  { "tax_compliance": false, "business_intelligence": true },
 *     "modules":   { "vat-compliance": false },
 *     "kpis":      { "rpd": false, "ebitda": true }
 *   }
 *
 * Resolution order at request time:
 *   1. Tenant-level service / module / KPI flag (existing tables)
 *   2. AND the user's role default (full / read-only)
 *   3. AND the user's explicit override (this column) if set.
 *
 * NULL = no overrides → role default applies. JSON column lets us
 * extend the schema without further migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
