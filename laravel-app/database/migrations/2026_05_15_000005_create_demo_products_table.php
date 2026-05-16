<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product catalogue for demo sandboxes — drives invoice-line generation
 * and gross-margin math (unit_price - default_cogs = unit gross profit).
 *
 * `category` powers product-mix breakdowns + segment KPIs in the
 * dashboard / revenue views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('category', 64)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('default_cogs', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_products');
    }
};
