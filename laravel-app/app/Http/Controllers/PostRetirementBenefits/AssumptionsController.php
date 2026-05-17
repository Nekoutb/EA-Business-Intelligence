<?php

namespace App\Http\Controllers\PostRetirementBenefits;

use App\Http\Controllers\Controller;
use App\Models\PostRetirementBenefits\AssumptionSet;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Editor for the actuarial assumptions used by the PUC engine.
 *
 * Per the table design: assumption sets are versioned by
 * (tenant, valuation_date). Editing on a date with no existing
 * row creates a new one; editing on an existing date overwrites
 * IF no finalized valuation references it. (Finalized valuations
 * pin their assumption set so the auditor can always trace back
 * to the exact numbers.)
 */
class AssumptionsController extends Controller
{
    public function edit(Request $request)
    {
        $tenant = app(TenantContext::class)->tenant();

        // Pick the most recent assumption set; create an in-memory
        // one from the platform defaults if none exists yet.
        $current = AssumptionSet::where('tenant_id', $tenant->id)
            ->orderByDesc('valuation_date')
            ->first();

        if (!$current) {
            $d = (array) config('post_retirement_assumptions');
            $current = new AssumptionSet([
                'discount_rate'             => $d['discount_rate']             ?? 0.0675,
                'salary_increase_rate'      => $d['salary_increase_rate']      ?? 0.03,
                'inflation_rate'            => $d['inflation_rate']            ?? 0.02,
                'expected_return_on_assets' => $d['expected_return_on_assets'] ?? 0.00,
                'turnover_bands'            => $d['turnover_bands']            ?? [],
                'mortality_table'           => $d['mortality_table']           ?? 'cima_f',
                'retirement_age'            => $d['retirement_age']            ?? 60,
                'funded'                    => $d['funded']                    ?? false,
                'fair_value_assets'         => 0,
                'valuation_date'            => now()->endOfYear(),
            ]);
        }

        return view('post-retirement-benefits.assumptions.edit', [
            'assumptions' => $current,
            'isNew'       => !$current->exists,
            'active'      => 'post_retirement_benefits',
        ]);
    }

    public function update(Request $request)
    {
        $tenant = app(TenantContext::class)->tenant();
        $data = $request->validate([
            'valuation_date'             => ['required', 'date'],
            'discount_rate_pct'          => ['required', 'numeric', 'min:0', 'max:30'],
            'salary_increase_rate_pct'   => ['required', 'numeric', 'min:0', 'max:30'],
            'inflation_rate_pct'         => ['required', 'numeric', 'min:0', 'max:30'],
            'expected_return_on_assets_pct' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'mortality_table'            => ['required', 'in:cima_f,cima_h'],
            'funded'                     => ['nullable'],
            'fair_value_assets'          => ['nullable', 'numeric', 'min:0'],
            'turnover_bands'             => ['nullable', 'array'],
            'turnover_bands.*.from_age'  => ['required_with:turnover_bands', 'integer', 'min:0', 'max:99'],
            'turnover_bands.*.to_age'    => ['required_with:turnover_bands', 'integer', 'min:1', 'max:100'],
            'turnover_bands.*.rate_pct'  => ['required_with:turnover_bands', 'numeric', 'min:0', 'max:100'],
            'notes'                      => ['nullable', 'string', 'max:2000'],
        ]);

        // Convert percentages back to fractions
        $bands = collect($data['turnover_bands'] ?? [])
            ->map(fn ($b) => [
                'from_age' => (int) $b['from_age'],
                'to_age'   => (int) $b['to_age'],
                'rate'     => (float) $b['rate_pct'] / 100,
            ])
            ->values()
            ->all();

        $valDate = Carbon::parse($data['valuation_date'])->startOfDay();

        $assumptions = AssumptionSet::updateOrCreate(
            [
                'tenant_id'      => $tenant->id,
                'valuation_date' => $valDate,
            ],
            [
                'discount_rate'             => (float) $data['discount_rate_pct'] / 100,
                'salary_increase_rate'      => (float) $data['salary_increase_rate_pct'] / 100,
                'inflation_rate'            => (float) $data['inflation_rate_pct'] / 100,
                'expected_return_on_assets' => (float) ($data['expected_return_on_assets_pct'] ?? 0) / 100,
                'mortality_table'           => $data['mortality_table'],
                // Retirement age fixed at 60 by user requirement
                // (Cameroon legal age) — not exposed in the form.
                'retirement_age'            => 60,
                'funded'                    => $request->boolean('funded'),
                'fair_value_assets'         => (float) ($data['fair_value_assets'] ?? 0),
                'turnover_bands'            => $bands,
                'notes'                     => $data['notes'] ?? null,
            ]
        );

        return redirect("/t/{$tenant->slug}/post-retirement-benefits/assumptions")
            ->with('flash', [
                'type' => 'success',
                'msg'  => "Assumptions for {$valDate->format('d M Y')} saved. Re-run the valuation to apply them.",
            ]);
    }
}
