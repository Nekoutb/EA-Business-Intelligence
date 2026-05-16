<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer master for demo sandboxes.
 *
 * `category` lets the demo show concentration risk + segmentation
 * (Hotel & Restaurant, Retail, Wholesale, Hospitality...).
 * `payment_terms_days` drives the due-date math when an invoice is
 * created against this customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_customers', function (Blueprint $table) {
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
        Schema::dropIfExists('demo_customers');
    }
};
