<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants table — root of the multi-tenancy model.
 *
 * Each tenant is a logical workspace with its own users, settings, module
 * activations, and Odoo connection. Tenant resolution is done via the URL
 * path prefix /t/{slug}, mapped by the ResolveTenant middleware.
 *
 * Status values:
 *   - active     : normal operation
 *   - trial      : same as active but with trial_ends_at set
 *   - suspended  : 403 with friendly page; super-admin can re-activate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('brand')->nullable();          // overrides config('bi.brand') in UI
            $table->string('logo_path')->nullable();      // public/uploads/<file>
            $table->string('status', 20)->default('active');
            $table->string('plan', 32)->default('standard');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
