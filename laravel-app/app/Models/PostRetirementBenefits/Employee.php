<?php

namespace App\Models\PostRetirementBenefits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee covered by a CBA — input to the PUC valuation.
 *
 * `cba_id` is optional: when null, the engine resolves the CBA
 * from `cba_key` via the CbaRegistry (i.e. uses the config-file
 * registry, no DB override). This makes onboarding cheaper —
 * tenants can import a roster with just the CBA key and rely on
 * platform defaults.
 *
 * `entitled_to_cnps_pension` drives the assumed exit event:
 *   - true  → employee retires at retirement age (60) and gets
 *             the "retirement" indemnity scale
 *   - false → employee is assumed to leave by involuntary
 *             termination (for valuation purposes) which gets
 *             the "termination" scale — typically more
 *             conservative (lower) than retirement.
 */
class Employee extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    protected $table = 'post_retirement_employees';

    protected $fillable = [
        'tenant_id', 'cba_id', 'cba_key',
        'employee_code', 'full_name', 'gender',
        'birth_date', 'hire_date', 'termination_date',
        'category', 'monthly_salary',
        'entitled_to_cnps_pension', 'active', 'notes',
    ];

    protected $casts = [
        'birth_date'               => 'date',
        'hire_date'                => 'date',
        'termination_date'         => 'date',
        'monthly_salary'           => 'decimal:2',
        'entitled_to_cnps_pension' => 'boolean',
        'active'                   => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cba(): BelongsTo
    {
        return $this->belongsTo(Cba::class, 'cba_id');
    }

    public function valuationLines(): HasMany
    {
        return $this->hasMany(ValuationLine::class);
    }

    // ── Derived attributes — computed on the fly, not cached.

    /**
     * Age in years at a given reference date (defaults to today).
     * Used by the engine to look up mortality + turnover by band.
     */
    public function ageAt(?\Carbon\Carbon $at = null): float
    {
        $at = $at ?? now();
        return round($this->birth_date->diffInDays($at) / 365.25, 2);
    }

    /**
     * Years of service at a given reference date (defaults to today).
     * Used to pick the right CBA bracket and project remaining service.
     */
    public function serviceYearsAt(?\Carbon\Carbon $at = null): float
    {
        $at = $at ?? now();
        return round($this->hire_date->diffInDays($at) / 365.25, 2);
    }

    /**
     * Years until normal retirement at age 60.
     * Negative if past retirement age (kept as a number so the
     * engine can warn rather than crash).
     */
    public function yearsToRetirement(?\Carbon\Carbon $at = null, int $retirementAge = 60): float
    {
        return round($retirementAge - $this->ageAt($at), 2);
    }
}
