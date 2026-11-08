<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\AssumptionSet;
use App\Models\PostRetirementBenefits\Employee;
use App\Models\PostRetirementBenefits\Valuation;
use App\Models\PostRetirementBenefits\ValuationLine;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrator: takes a tenant + valuation date, iterates every
 * active employee through the PUC engine, persists the headline
 * valuation + per-line detail, and returns the saved Valuation
 * model.
 *
 * Idempotent per (tenant, valuation_date, status='draft'). Calling
 * `run()` twice on the same date overwrites the previous draft
 * but never touches a finalized run.
 */
final class ValuationRunner
{
    public function __construct(
        private readonly AssumptionResolver $assumptions,
    ) {}

    /**
     * Run a full valuation for the tenant as at `$valuationDate`.
     * Returns the persisted Valuation row.
     */
    public function run(Tenant $tenant, Carbon $valuationDate): Valuation
    {
        return ea_tdb()->transaction(function () use ($tenant, $valuationDate) {
            // 1. Resolve / persist the assumption set.
            $assumptions = $this->assumptions->resolveOrSeed($tenant, $valuationDate);

            // 2. Build the engine bundle from the assumption set.
            $decrements = new DecrementTable(
                turnoverBands: $assumptions->turnover_bands ?: [],
                mortalityTable: $assumptions->mortality_table ?: 'cima_f',
                retirementAge: (int) $assumptions->retirement_age,
            );
            $engine = new ProjectedUnitCreditEngine(
                decrements: $decrements,
                discountRate: (float) $assumptions->discount_rate,
                salaryIncreaseRate: (float) $assumptions->salary_increase_rate,
                retirementAge: (int) $assumptions->retirement_age,
            );

            // 3. Look up the prior valuation (for the roll-forward).
            $prior = Valuation::where('tenant_id', $tenant->id)
                ->where('valuation_date', '<', $valuationDate)
                ->orderByDesc('valuation_date')
                ->first();

            // 4. Drop any existing draft for this date — runs are
            //    overwrites until finalized.
            Valuation::where('tenant_id', $tenant->id)
                ->where('valuation_date', $valuationDate)
                ->where('status', 'draft')
                ->delete();

            // 5. Create the new draft row (totals filled in after lines).
            $valuation = Valuation::create([
                'tenant_id'             => $tenant->id,
                'assumption_id'         => $assumptions->id,
                'valuation_date'        => $valuationDate,
                'prior_valuation_date'  => $prior?->valuation_date,
                'prior_valuation_id'    => $prior?->id,
                'pbo_opening'           => $prior?->pbo_closing ?? 0,
                'service_cost'          => 0,
                'interest_cost'         => 0,
                'benefit_payments'      => 0,
                'actuarial_gain_loss'   => 0,
                'plan_amendments'       => 0,
                'settlements'           => 0,
                'pbo_closing'           => 0,
                'fair_value_assets'     => (float) $assumptions->fair_value_assets,
                'funded_status'         => 0,
                'net_periodic_benefit_cost' => 0,
                'duration_years'        => null,
                'headcount_blue_collar' => 0,
                'headcount_supervisor'  => 0,
                'headcount_manager'     => 0,
                'status'                => 'draft',
            ]);

            // 6. Walk every active employee through the engine.
            $employees = Employee::where('tenant_id', $tenant->id)
                ->where('active', true)
                ->get();

            $totalPbo = 0.0;
            $totalServiceCost = 0.0;
            $totalRet = 0.0;
            $totalTerm = 0.0;
            $totalDeath = 0.0;
            $totalPayByYear = [];
            $headcount = ['blue_collar' => 0, 'supervisor' => 0, 'manager' => 0];

            // First pass — run the engine for every employee and
            // accumulate portfolio totals. Lines are persisted in a
            // second pass once the (opening-based) interest cost is known,
            // so the per-employee interest rows reconcile to the header.
            $results = [];
            foreach ($employees as $e) {
                $cba = CbaRegistry::forEmployee($e, $tenant);
                if (!$cba) {
                    // Skip rather than crash — log via valuation
                    // payload notes (TODO Phase 4: surface in UI).
                    continue;
                }

                $r = $engine->valueEmployee($e, $cba, $valuationDate);
                $results[] = ['e' => $e, 'r' => $r];

                $totalPbo         += $r['pbo'];
                $totalServiceCost += $r['service_cost'];
                $totalRet         += $r['pbo_retirement'];
                $totalTerm        += $r['pbo_termination'];
                $totalDeath       += $r['pbo_death'];
                foreach ($r['expected_payments_by_year'] as $year => $amount) {
                    $totalPayByYear[$year] = ($totalPayByYear[$year] ?? 0) + $amount;
                }
                $headcount[$e->category] = ($headcount[$e->category] ?? 0) + 1;
            }

            // Interest cost = discount rate × OPENING PBO (the prior
            // period's closing liability), per the IAS 19 unwind. This is
            // a portfolio roll-forward figure the engine can't produce — a
            // single employee has no cross-period "opening" — so we
            // compute it here. On a first valuation there's no prior
            // liability to unwind → 0. (Previously this summed the engine's
            // closing-PBO × rate per employee, which overstated interest
            // cost and NPBC and distorted the actuarial gain/loss.)
            $discountRate       = (float) $assumptions->discount_rate;
            $openingForInterest = $prior ? (float) $prior->pbo_closing : 0.0;
            $totalInterestCost  = $openingForInterest * $discountRate;

            // Second pass — persist per-employee lines, prorating the
            // opening-based interest across employees by their share of
            // the closing PBO so the detail rows tie back to the header.
            foreach ($results as $row) {
                $e = $row['e'];
                $r = $row['r'];
                $lineInterest = $totalPbo > 0
                    ? $totalInterestCost * ($r['pbo'] / $totalPbo)
                    : 0.0;

                ValuationLine::create([
                    'valuation_id'                 => $valuation->id,
                    'employee_id'                  => $e->id,
                    'tenant_id'                    => $tenant->id,
                    'age_at_valuation'             => $r['age_at_valuation'],
                    'service_years_at_valuation'   => $r['service_years_at_valuation'],
                    'monthly_salary_at_valuation'  => $r['monthly_salary_at_valuation'],
                    'pbo'                          => $r['pbo'],
                    'service_cost'                 => $r['service_cost'],
                    'interest_cost'                => round($lineInterest, 2),
                    'pbo_retirement'               => $r['pbo_retirement'],
                    'pbo_termination'              => $r['pbo_termination'],
                    'pbo_death'                    => $r['pbo_death'],
                    'expected_payments_by_year'    => $r['expected_payments_by_year'],
                ]);
            }

            // 7. Compute headline numbers.
            ksort($totalPayByYear);

            // Actuarial gain/loss = closing - (opening + service + interest - benefits paid)
            // ONLY meaningful when there's a prior valuation. On a first
            // valuation we have nothing to compare against — the PBO
            // simply IS what it is, not a gain or loss vs expectation.
            // Tenants can enter benefit_payments manually in Phase 4 to
            // drive the experience-vs-expected variance.
            $pboOpening = (float) $valuation->pbo_opening;
            if ($prior) {
                $expectedClosing = $pboOpening + $totalServiceCost + $totalInterestCost;
                $actuarialGainLoss = $totalPbo - $expectedClosing;
            } else {
                // First valuation — no prior to compare. Set opening
                // = closing so the roll-forward is empty (the PBO
                // wasn't "built" during this period; it's the
                // initial-recognition figure).
                $valuation->pbo_opening = round($totalPbo, 2);
                $actuarialGainLoss = 0.0;
            }

            $fairValueAssets = (float) $assumptions->fair_value_assets;
            $fundedStatus = $fairValueAssets - $totalPbo;
            $expectedReturn = $fairValueAssets * (float) $assumptions->expected_return_on_assets;
            $npbc = $totalServiceCost + $totalInterestCost - $expectedReturn;

            // Approximate liability duration: dollar-weighted year
            // of expected payments. (Mercer reported 13y for
            // Globeleq — this approximation gets close.)
            $duration = $this->approximateDuration($totalPayByYear, $valuationDate, $totalPbo);

            $valuation->update([
                'pbo_opening'               => round((float) $valuation->pbo_opening, 2),
                'service_cost'              => round($totalServiceCost, 2),
                'interest_cost'             => round($totalInterestCost, 2),
                'actuarial_gain_loss'       => round($actuarialGainLoss, 2),
                'pbo_closing'               => round($totalPbo, 2),
                'funded_status'             => round($fundedStatus, 2),
                'net_periodic_benefit_cost' => round($npbc, 2),
                'duration_years'            => round($duration, 2),
                'headcount_blue_collar'     => $headcount['blue_collar'],
                'headcount_supervisor'      => $headcount['supervisor'],
                'headcount_manager'         => $headcount['manager'],
                'payload'                   => [
                    'pbo_by_decrement' => [
                        'retirement'  => round($totalRet, 2),
                        'termination' => round($totalTerm, 2),
                        'death'       => round($totalDeath, 2),
                    ],
                    'expected_payments_by_year' => $totalPayByYear,
                    'assumptions_snapshot' => $assumptions->only([
                        'discount_rate', 'salary_increase_rate',
                        'inflation_rate', 'expected_return_on_assets',
                        'turnover_bands', 'mortality_table',
                        'retirement_age', 'funded', 'fair_value_assets',
                    ]),
                    'employee_count' => $employees->count(),
                ],
            ]);

            return $valuation->fresh();
        });
    }

    /**
     * Macaulay-style duration approximation.
     * Σ (t × CF_t) / PBO. Years past 50 are clipped to 50 to avoid
     * runaways from bad data, while no longer truncating the genuine
     * maturity of a young workforce (a 25-year-old retiring at 60 is
     * 35 years out — the old 30y clip understated their disclosed
     * duration). 50y comfortably exceeds the longest real horizon
     * (retirementAge − minimum hire age).
     */
    private function approximateDuration(array $payByYear, Carbon $valuationDate, float $pbo): float
    {
        if ($pbo <= 0 || empty($payByYear)) return 0.0;
        $baseYear = (int) $valuationDate->year;
        $weightedSum = 0.0;
        $sum = 0.0;
        foreach ($payByYear as $year => $amount) {
            $t = max(0, min(50, $year - $baseYear));
            $weightedSum += $t * (float) $amount;
            $sum += (float) $amount;
        }
        if ($sum <= 0) return 0.0;
        return $weightedSum / $sum;
    }
}
