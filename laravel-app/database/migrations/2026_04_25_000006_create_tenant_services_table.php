<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant service activation flags.
 *
 * EAMS organises features under top-level services (Business Intelligence,
 * Tax Compliance, Financial Reporting, Financial Planning). Each service
 * groups one-or-more modules; disabling a service hides every module it
 * owns regardless of the per-module flag in tenant_modules.
 *
 * One row per (tenant, service_key). Absence of a row = disabled.
 * `service_key` matches entries in config/modules.php['services'].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('service_key', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'service_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_services');
    }
};
