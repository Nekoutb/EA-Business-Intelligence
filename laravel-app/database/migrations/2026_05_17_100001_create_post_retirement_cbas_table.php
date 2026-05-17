<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-specific Collective Bargaining Agreements for the
 * Post-Retirement Benefits module.
 *
 * The defaults (Commerce, Statutory Minimum) ship in
 * config/post_retirement_cbas.php as a fallback registry. This
 * table lets a tenant override the brackets for a specific CBA
 * or define a fully custom one. CbaRegistry::lookup() checks
 * the table first, then falls back to the config file.
 *
 * Why DB-overridable: when a sector renegotiates its CBA (every
 * 3-5 years typically), tenants can patch their installation
 * without a code release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_retirement_cbas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()
                  ->constrained('tenants')->cascadeOnDelete();
            // 'key' matches the config-file CBA key for an override,
            // or a tenant-chosen slug for a custom one.
            $table->string('key', 64);
            $table->string('name');
            $table->string('source_doc')->nullable();
            $table->unsignedSmallInteger('min_service_months')->default(12);

            // Bracket arrays match the config-file shape exactly.
            // We store as JSON so the calculation engine doesn't
            // care whether the source is config or DB.
            $table->json('notice_period_months');
            $table->json('termination_brackets');
            $table->json('retirement_brackets');
            $table->json('death_brackets');
            $table->json('notes')->nullable();

            $table->timestamps();

            // A tenant can have multiple CBAs (e.g. Commerce and
            // a custom override) but only one per key.
            $table->unique(['tenant_id', 'key']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_retirement_cbas');
    }
};
