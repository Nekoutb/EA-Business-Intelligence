<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed asset register for demo sandboxes. Drives the Fixed Assets module:
 * acquisition cost, useful life, depreciation method, accumulated dep,
 * net book value at the as-of date.
 *
 * Depreciation in the demo is straight-line: annual_dep = cost / useful_life
 * monthly_dep = annual_dep / 12 — computed in PHP at render time. NBV =
 * acquisition_cost − accumulated_dep_at_as_of_date.
 *
 * `category`:
 *   Buildings · Vehicles · Equipment · Furniture · IT · Other
 * `account_code`:
 *   2412 (buildings) · 2454 (vehicles) · 2440 (equipment) · 2440 (furniture) · 2421 (IT)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('asset_code', 32);                 // FA-001
            $table->string('name', 191);
            $table->string('category', 64);                   // Buildings, Vehicles, etc.
            $table->string('account_code', 16)->nullable();   // 2412, 2454, etc.
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 18, 2);
            $table->unsignedSmallInteger('useful_life_months')->default(60);
            $table->string('depreciation_method', 16)->default('linear');   // linear|degressive
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_value', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_code']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_fixed_assets');
    }
};
