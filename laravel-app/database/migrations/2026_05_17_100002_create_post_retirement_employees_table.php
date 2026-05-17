<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee roster for the Post-Retirement Benefits valuation.
 *
 * One row per active or recently-terminated employee covered by
 * a CBA. This is the input to the Projected Unit Credit engine —
 * the engine iterates this roster, applies decrements (mortality,
 * turnover), projects salaries forward to each separation date,
 * applies the CBA bracket schedule, and discounts back to today.
 *
 * Identity-only data is intentionally minimal — no national ID,
 * no full address. The valuation only needs demographic + service
 * + compensation fields. Tenants can extend in a custom JSON
 * field if they need to attach more (rare in practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_retirement_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cba_id')->nullable()
                  ->constrained('post_retirement_cbas')->nullOnDelete();
            // CBA key fallback — used when cba_id is null and we
            // need to resolve from the config registry instead.
            $table->string('cba_key', 64)->nullable();

            // Identity (minimal)
            $table->string('employee_code', 64);
            $table->string('full_name', 191);
            $table->enum('gender', ['M', 'F', 'U'])->default('U');

            // Dates — the three that drive the calculation
            $table->date('birth_date');
            $table->date('hire_date');
            $table->date('termination_date')->nullable();

            // Classification — drives notice period table + sometimes
            // CBA selection. Matches the Mercer Globeleq categories.
            $table->enum('category', ['blue_collar', 'supervisor', 'manager']);

            // Compensation
            $table->decimal('monthly_salary', 18, 2);
            // Whether they qualify for CNPS pension on retirement.
            // If yes → retirement is the assumed separation event.
            // If no  → involuntary termination is the assumed event
            //          (under Article 49 CBA family — bigger indemnity).
            $table->boolean('entitled_to_cnps_pension')->default(true);

            // Soft-delete-style "active in plan" flag — distinguishes
            // current participants from those who've left. The latter
            // are kept for prior-period reconciliation but excluded
            // from new valuations.
            $table->boolean('active')->default(true);

            // Optional notes — sometimes useful for auditor follow-ups.
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'employee_code']);
            $table->index(['tenant_id', 'active']);
            $table->index('birth_date');
            $table->index('hire_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_retirement_employees');
    }
};
