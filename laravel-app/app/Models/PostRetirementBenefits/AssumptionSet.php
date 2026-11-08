<?php

namespace App\Models\PostRetirementBenefits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per (tenant, valuation_date) — the actuarial assumptions
 * used for a specific year's valuation.
 *
 * Rationale for keeping history: when assumptions change year to
 * year (discount rate goes up/down with BEAC, salary increase
 * resets, etc.), the auditor needs to tie back each prior
 * valuation to the exact assumption set that produced it. We
 * never mutate a finalized AssumptionSet — a new one is created.
 */
class AssumptionSet extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    protected $table = 'post_retirement_assumptions';

    protected $fillable = [
        'tenant_id', 'valuation_date',
        'discount_rate', 'salary_increase_rate', 'inflation_rate',
        'expected_return_on_assets',
        'turnover_bands', 'mortality_table', 'retirement_age',
        'funded', 'fair_value_assets', 'notes',
    ];

    protected $casts = [
        'valuation_date'             => 'date',
        'discount_rate'              => 'decimal:6',
        'salary_increase_rate'       => 'decimal:6',
        'inflation_rate'             => 'decimal:6',
        'expected_return_on_assets'  => 'decimal:6',
        'turnover_bands'             => 'array',
        'retirement_age'             => 'integer',
        'funded'                     => 'boolean',
        'fair_value_assets'          => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class, 'assumption_id');
    }
}
