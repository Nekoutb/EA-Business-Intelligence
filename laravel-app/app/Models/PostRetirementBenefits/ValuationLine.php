<?php

namespace App\Models\PostRetirementBenefits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-employee detail line of one valuation.
 *
 * Stored as a snapshot — once written, the line is immutable
 * (employee record changes don't propagate back). This makes the
 * valuation reproducible from saved data even if the source
 * employee was deleted or edited later.
 */
class ValuationLine extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    protected $table = 'post_retirement_valuation_lines';

    protected $fillable = [
        'valuation_id', 'employee_id', 'tenant_id',
        'age_at_valuation', 'service_years_at_valuation',
        'monthly_salary_at_valuation',
        'pbo', 'service_cost', 'interest_cost',
        'pbo_retirement', 'pbo_termination', 'pbo_death',
        'expected_payments_by_year',
    ];

    protected $casts = [
        'age_at_valuation'             => 'decimal:2',
        'service_years_at_valuation'   => 'decimal:2',
        'monthly_salary_at_valuation'  => 'decimal:2',
        'pbo'                          => 'decimal:2',
        'service_cost'                 => 'decimal:2',
        'interest_cost'                => 'decimal:2',
        'pbo_retirement'               => 'decimal:2',
        'pbo_termination'              => 'decimal:2',
        'pbo_death'                    => 'decimal:2',
        'expected_payments_by_year'    => 'array',
    ];

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
