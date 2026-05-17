<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee valuation detail line. One row per
 * (valuation, employee) pair. Stores the PBO + service cost +
 * interest cost + expected benefit payments computed by the
 * PUC engine for that employee.
 *
 * Kept separate from the headline `post_retirement_valuations`
 * row so a tenant can drill into "why is the total what it is?"
 * by sorting/filtering by employee. Also feeds the projected
 * cash-flow chart and the participant-level audit appendix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_retirement_valuation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valuation_id')
                  ->constrained('post_retirement_valuations')->cascadeOnDelete();
            $table->foreignId('employee_id')
                  ->constrained('post_retirement_employees')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Snapshot of demographics at valuation date (so the
            // line is self-describing even if the employee record
            // changes later).
            $table->decimal('age_at_valuation',         5, 2);
            $table->decimal('service_years_at_valuation', 5, 2);
            $table->decimal('monthly_salary_at_valuation', 18, 2);

            // Core PUC outputs
            $table->decimal('pbo',           18, 2);   // Projected Benefit Obligation
            $table->decimal('service_cost',  18, 2);   // PV of upcoming year's accrual
            $table->decimal('interest_cost', 18, 2);   // PBO_opening × discount_rate

            // Probability-weighted decrement breakdown
            // (for transparency on which scenario drives the number)
            $table->decimal('pbo_retirement',  18, 2)->default(0);
            $table->decimal('pbo_termination', 18, 2)->default(0);
            $table->decimal('pbo_death',       18, 2)->default(0);

            // Projected benefit payments (when the indemnity is
            // expected to be paid). Bucketed by year for the
            // Appendix M cash-flow chart in the Mercer-style report.
            $table->json('expected_payments_by_year');

            $table->timestamps();

            $table->unique(['valuation_id', 'employee_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_retirement_valuation_lines');
    }
};
