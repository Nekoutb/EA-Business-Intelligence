<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier master for demo sandboxes. Mirror of demo_customers on the
 * buy-side — drives the Payables and Procurement modules.
 *
 * `category` lets the demo show spend concentration (Beverages,
 * Logistics, Office, Utilities) and per-category aging.
 * `payment_terms_days` is what the supplier offers US (negotiated terms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('category', 64)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('country', 64)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_suppliers');
    }
};
