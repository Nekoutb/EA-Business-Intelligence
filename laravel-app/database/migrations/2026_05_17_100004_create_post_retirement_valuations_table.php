<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "Run Valuation" action. Records the totals that
 * roll up the per-employee detail (post_retirement_valuation_lines)
 * and snapshots the assumption set + plan provisions used so the
 * computation is reproducible from the saved data alone.
 *
 * Status lifecycle:
 *   draft      → calculator ran but admin hasn't committed yet
 *   finalized  → admin marked as the official valuation for the
 *                period; gets included in the year-end report
 *
 * (Per the user: no separate sign-off workflow. External signature
 * is applied to the exported Word document outside the system.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_retirement_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('assumption_id')
                  ->constrained('post_retirement_assumptions')->cascadeOnDelete();

            $table->date('valuation_date');
            $table->date('prior_valuation_date')->nullable();
            $table->foreignId('prior_valuation_id')->nullable()
                  ->constrained('post_retirement_valuations')->nullOnDelete();

            // Roll-forward of the projected benefit obligation
            // (matches Mercer Appendix A "Change in benefit obligation")
            $table->decimal('pbo_opening',         18, 2)->default(0);
            $table->decimal('service_cost',        18, 2)->default(0);
            $table->decimal('interest_cost',       18, 2)->default(0);
            $table->decimal('benefit_payments',    18, 2)->default(0);
            $table->decimal('actuarial_gain_loss', 18, 2)->default(0);
            $table->decimal('plan_amendments',     18, 2)->default(0);
            $table->decimal('settlements',         18, 2)->default(0);
            $table->decimal('pbo_closing',         18, 2)->default(0);

            // Funding
            $table->decimal('fair_value_assets', 18, 2)->default(0);
            $table->decimal('funded_status',     18, 2)->default(0);

            // Net periodic benefit cost (P&L charge for the year)
            $table->decimal('net_periodic_benefit_cost', 18, 2)->default(0);

            // Duration of liabilities (years) — used to set the
            // discount rate by maturity-matching to BEAC bond yields.
            $table->decimal('duration_years', 6, 2)->nullable();

            // Headcount split (matches Mercer "by employee type")
            $table->unsignedSmallInteger('headcount_blue_collar')->default(0);
            $table->unsignedSmallInteger('headcount_supervisor')->default(0);
            $table->unsignedSmallInteger('headcount_manager')->default(0);

            // Full result blob — projected cash flows by year,
            // per-decrement breakdown, OCI components, etc. Read by
            // the Mercer-style report exporter.
            $table->json('payload')->nullable();

            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Explicit name: Laravel's generated one is 66 characters and
            // MySQL caps identifiers at 64, so a fresh database failed here.
            $table->unique(['tenant_id', 'valuation_date', 'status'], 'prv_tenant_date_status_uq');
            $table->index(['tenant_id', 'valuation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_retirement_valuations');
    }
};
