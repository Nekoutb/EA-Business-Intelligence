<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant on/off flag for individual Business Intelligence Dashboard
 * KPI cards. Mirrors tenant_modules but at finer granularity — each
 * dashboard KPI (Revenue YTD, RpC, EBITDA, etc.) has its own row.
 *
 * Absence of a row falls back to the registry default in
 * config/dashboard_kpis.php. Toggled by super admins via /admin/tenants/{id};
 * read by the tenant Dashboard view to decide whether to render each card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_dashboard_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kpi_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'kpi_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_dashboard_kpis');
    }
};
