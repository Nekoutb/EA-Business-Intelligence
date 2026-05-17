<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant actuarial assumptions used for one or more valuations.
 *
 * Defaults seed from config/post_retirement_assumptions.php on the
 * tenant's first visit to the module. The tenant admin can then
 * override discount_rate / salary_increase / turnover_bands /
 * mortality_table / funded as the local fact pattern requires.
 *
 * We keep history — every valuation snapshots the row id of the
 * assumptions used. When assumptions change, prior valuations
 * still tie back to the exact assumption set that produced them,
 * so the auditor can trace the numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_retirement_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Effective valuation date (year-end usually). We
            // version assumptions per year — the tenant edits a new
            // row for each new valuation rather than mutating the
            // historical one.
            $table->date('valuation_date');

            // Economic assumptions
            $table->decimal('discount_rate',          8, 6);  // e.g. 0.067500
            $table->decimal('salary_increase_rate',   8, 6);
            $table->decimal('inflation_rate',         8, 6);
            $table->decimal('expected_return_on_assets', 8, 6)->default(0);

            // Demographic
            $table->json('turnover_bands');                    // [{from_age, to_age, rate}]
            $table->string('mortality_table', 32);             // 'cima_f' | 'cima_h' | custom
            $table->unsignedTinyInteger('retirement_age')->default(60);

            // Funding
            $table->boolean('funded')->default(false);
            $table->decimal('fair_value_assets', 18, 2)->default(0);

            // Free-form notes the tenant can attach (e.g. "discount
            // rate reduced 25bp following BEAC rate cut, source:
            // BEAC press release Jan 2026").
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'valuation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_retirement_assumptions');
    }
};
