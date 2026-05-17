<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\AssumptionSet;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Resolves the assumption set to use for a given (tenant,
 * valuation_date). If one already exists, returns it. Otherwise
 * seeds a new row from config/post_retirement_assumptions.php
 * defaults.
 *
 * Kept as a service rather than inlined into the controller so
 * the same logic is used by the runner, the assumption editor,
 * and the future sensitivity analyzer.
 */
final class AssumptionResolver
{
    public function resolveOrSeed(Tenant $tenant, Carbon $valuationDate): AssumptionSet
    {
        $existing = AssumptionSet::where('tenant_id', $tenant->id)
            ->whereDate('valuation_date', $valuationDate)
            ->first();
        if ($existing) {
            return $existing;
        }

        // Seed from config defaults.
        $defaults = (array) config('post_retirement_assumptions', []);
        return AssumptionSet::create([
            'tenant_id'                 => $tenant->id,
            'valuation_date'            => $valuationDate,
            'discount_rate'             => (float) ($defaults['discount_rate']             ?? 0.0675),
            'salary_increase_rate'      => (float) ($defaults['salary_increase_rate']      ?? 0.03),
            'inflation_rate'            => (float) ($defaults['inflation_rate']            ?? 0.02),
            'expected_return_on_assets' => (float) ($defaults['expected_return_on_assets'] ?? 0.00),
            'turnover_bands'            => $defaults['turnover_bands'] ?? [],
            'mortality_table'           => (string) ($defaults['mortality_table']  ?? 'cima_f'),
            'retirement_age'            => (int)    ($defaults['retirement_age']   ?? 60),
            'funded'                    => (bool)   ($defaults['funded']           ?? false),
            'fair_value_assets'         => 0,
        ]);
    }
}
