<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant configuration store — replaces .env for tenant-scoped values.
 *
 * Examples of keys:
 *   ODOO_URL, ODOO_DB, ODOO_USERNAME, ODOO_API_KEY, ODOO_PROTOCOL,
 *   REVENUE_SOURCE, AI_PROVIDER, AI_MODEL, AI_API_KEY,
 *   BUDGET_MONTHLY_REVENUE, BUDGET_MONTHLY_OUTFITS, BUDGET_MONTHLY_COGS,
 *   BUDGET_MONTHLY_DISCOUNTS, BUDGET_MONTHLY_CUSTOMERS
 *
 * Values are stored as text. The model layer encrypts secrets transparently
 * (any key matching /KEY|SECRET|PASSWORD/i) using Laravel's Crypt facade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
