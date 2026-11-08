<?php

namespace App\Models\PostRetirementBenefits;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "Run Valuation" outcome. Aggregates totals across all
 * employees + snapshots the roll-forward (Mercer Appendix A).
 *
 * `funded_status` is the balance-sheet number:
 *   funded_status = fair_value_assets − pbo_closing
 * A negative number is the provision recognized as a liability
 * on account 197 (Provisions pour engagements de retraite).
 *
 * `net_periodic_benefit_cost` is the P&L charge for the year:
 *   NPBC = service_cost + interest_cost − expected_return_on_assets
 *        + amortization_of_prior_service + amortization_of_net_gain_loss
 * (in this Phase 1 we only carry the totals — the components
 * roll up from the per-employee lines.)
 */
class Valuation extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    protected $table = 'post_retirement_valuations';

    protected $fillable = [
        'tenant_id', 'assumption_id',
        'valuation_date', 'prior_valuation_date', 'prior_valuation_id',
        'pbo_opening', 'service_cost', 'interest_cost',
        'benefit_payments', 'actuarial_gain_loss',
        'plan_amendments', 'settlements', 'pbo_closing',
        'fair_value_assets', 'funded_status',
        'net_periodic_benefit_cost', 'duration_years',
        'headcount_blue_collar', 'headcount_supervisor', 'headcount_manager',
        'payload', 'status', 'finalized_at', 'finalized_by',
    ];

    protected $casts = [
        'valuation_date'              => 'date',
        'prior_valuation_date'        => 'date',
        'finalized_at'                => 'datetime',
        'pbo_opening'                 => 'decimal:2',
        'service_cost'                => 'decimal:2',
        'interest_cost'               => 'decimal:2',
        'benefit_payments'            => 'decimal:2',
        'actuarial_gain_loss'         => 'decimal:2',
        'plan_amendments'             => 'decimal:2',
        'settlements'                 => 'decimal:2',
        'pbo_closing'                 => 'decimal:2',
        'fair_value_assets'           => 'decimal:2',
        'funded_status'               => 'decimal:2',
        'net_periodic_benefit_cost'   => 'decimal:2',
        'duration_years'              => 'decimal:2',
        'payload'                     => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assumptionSet(): BelongsTo
    {
        return $this->belongsTo(AssumptionSet::class, 'assumption_id');
    }

    public function priorValuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class, 'prior_valuation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ValuationLine::class);
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
