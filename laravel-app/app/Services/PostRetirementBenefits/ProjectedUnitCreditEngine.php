<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\Employee;
use Carbon\Carbon;

/**
 * Projected Unit Credit (PUC) actuarial calculation engine.
 *
 * For each employee we walk forward year by year from the
 * measurement date up to the retirement age. In each future year
 * we:
 *
 *   1. Project the salary forward at the assumed escalation rate.
 *   2. Compute the indemnity that would be paid IF the employee
 *      left in that year, applying the relevant CBA bracket
 *      schedule to the projected salary + cumulative service.
 *   3. Multiply by the probability of each scenario (death,
 *      turnover, retirement) happening in that year, given they
 *      have remained active up to it.
 *   4. Discount the expected payment back to the measurement
 *      date at the discount rate.
 *
 * Sum across all future years = Projected Benefit Obligation
 * (PBO). The service cost is computed as the PUC method specifies:
 * the PV of the benefit attributable to ONE additional year of
 * service after the measurement date.
 *
 * This is the same recipe Mercer uses in the Globeleq report
 * (Appendix D "Actuarial Methods"). Mathematically:
 *
 *   PBO = Σ_t  EPB(t) × p_active(t) × p_event(t) × v^t
 *
 * where v = 1 / (1 + discount_rate), p_active(t) is the prob of
 * staying active until year t, p_event(t) is the prob of leaving
 * via the relevant decrement in year t, and EPB(t) is the
 * expected indemnity if leaving in year t.
 */
final class ProjectedUnitCreditEngine
{
    public function __construct(
        private readonly DecrementTable $decrements,
        private readonly float $discountRate,
        private readonly float $salaryIncreaseRate,
        private readonly int $retirementAge,
    ) {}

    /**
     * Compute one employee's PUC outputs against a single CBA.
     *
     * @return array{
     *   pbo:              float,
     *   service_cost:     float,
     *   interest_cost:    float,
     *   pbo_retirement:   float,
     *   pbo_termination:  float,
     *   pbo_death:        float,
     *   expected_payments_by_year: array<int, float>,
     *   age_at_valuation: float,
     *   service_years_at_valuation: float,
     *   monthly_salary_at_valuation: float,
     * }
     */
    public function valueEmployee(Employee $e, array $cba, Carbon $valuationDate): array
    {
        $ageNow     = $e->ageAt($valuationDate);
        $serviceNow = $e->serviceYearsAt($valuationDate);
        $salaryNow  = (float) $e->monthly_salary;
        $gender     = $e->gender;   // 'M' | 'F' | 'U' — drives sex-distinct mortality

        $ageInt = (int) floor($ageNow);
        // Integer-age horizon for the decrement loop (qx / turnover are
        // tabulated by integer age). Cap at retirement age — if the
        // employee is already past it the engine computes a PBO equal to
        // the current accrued indemnity (paid immediately).
        $yearsToRetirement = max(0, $this->retirementAge - $ageInt);

        // Fractional age. The loop index `t` steps INTEGER ages, but the
        // elapsed time from the valuation date to integer-age (ageInt+t)
        // is (t − frac): an employee aged 45.9 reaches 60 in 14.1 years,
        // not 15. Using `t` as the discount exponent over-discounts the
        // dominant retirement slice and systematically understates the
        // PBO (~1.5–2% on average). We track the EXACT elapsed time `tau`
        // for projection + discounting while keeping integer ages for the
        // decrement lookups.
        $frac = $ageNow - $ageInt;                                  // in [0, 1)
        $n    = max(0.0, (float) $this->retirementAge - $ageNow);   // exact years to retirement

        // Benefit eligibility floor — no indemnity accrues below the
        // CBA's minimum service (Cameroon law / CBA: typically 1 year).
        // Without this the engine pays bracket indemnity on sub-threshold
        // early exits (death / turnover in the first year), overstating
        // those slices.
        $minServiceYears = ((int) ($cba['min_service_months'] ?? 0)) / 12.0;

        // Which bracket scale applies to each decrement.
        $retirementBrackets  = $cba['retirement_brackets']  ?? [];
        $terminationBrackets = $cba['termination_brackets'] ?? [];
        $deathBrackets       = $cba['death_brackets']       ?? [];

        // NOTE on entitled_to_cnps_pension: it does NOT switch the benefit
        // scale. Per the platform spec ("retirement age 60 enforced for
        // all"), every employee who reaches 60 is valued on the RETIREMENT
        // scale — Art. 46 CBA treats the departure as a retirement for the
        // employer's indemnity obligation regardless of CNPS pension
        // entitlement. If non-CNPS staff should instead be valued on the
        // (lower) termination scale, that is a one-line change in the
        // retirement-year branch below and it changes the signed liability
        // — surface it to the actuary before flipping it.

        $pboTotal = 0.0;
        $pboRet   = 0.0;
        $pboTerm  = 0.0;
        $pboDeath = 0.0;
        $payByYear = [];

        $v = 1.0 / (1.0 + $this->discountRate);
        $gSalary = 1.0 + $this->salaryIncreaseRate;

        // We iterate from year 0 (now) to year (retirement - age).
        // At year `t` the employee has age = ageNow + t, service =
        // serviceNow + t, salary = salaryNow × (1+g)^t.
        //
        // The probability they leave in year t:
        //   p_active_until(t) × p_leaving_in_year_t
        // where p_active_until(t) = product of (1 - decrement) for
        // ages ageInt through ageInt+t-1.

        $cumulativeActiveProb = 1.0;
        for ($t = 0; $t <= $yearsToRetirement; $t++) {
            $ageAtT = $ageInt + $t;
            // Exact elapsed time from the valuation date, used for salary
            // projection + discounting (NOT the integer index $t). At
            // t=0 → 0 (today); at the final iteration → n (the exact
            // retirement date, age = retirementAge).
            $tau        = $t === $yearsToRetirement ? $n : max(0.0, $t - $frac);
            $serviceAtT = $serviceNow + $tau;
            $salaryAtT  = $salaryNow * pow($gSalary, $tau);

            // Indemnity for each possible exit at this point, gated by the
            // CBA minimum-service floor (no benefit accrues below it).
            $eligible = $serviceAtT >= $minServiceYears;
            $indemnityRetirement  = $eligible ? $salaryAtT * CbaRegistry::applyBrackets($retirementBrackets,  $serviceAtT) : 0.0;
            $indemnityTermination = $eligible ? $salaryAtT * CbaRegistry::applyBrackets($terminationBrackets, $serviceAtT) : 0.0;
            $indemnityDeath       = $eligible ? $salaryAtT * CbaRegistry::applyBrackets($deathBrackets,       $serviceAtT) : 0.0;

            // Probability of each scenario. At retirement age
            // (t === yearsToRetirement) all remaining active prob retires;
            // before, it's death + turnover only (sex-distinct mortality).
            if ($t === $yearsToRetirement) {
                $pRet  = $cumulativeActiveProb;
                $pDth  = 0.0;
                $pTurn = 0.0;
            } else {
                $shares = $this->decrements->decrementSharesAt($ageAtT, $gender);
                $pRet  = $cumulativeActiveProb * $shares['retirement'];
                $pDth  = $cumulativeActiveProb * $shares['death'];
                $pTurn = $cumulativeActiveProb * $shares['turnover'];
            }

            // Discounting. Retirement is a point event at exactly age =
            // retirementAge → time tau = n → v^n. Death / turnover occur
            // DURING integer-age-year t, on average at its midpoint, so we
            // discount those slices at v^(tau + 0.5) (mid-year convention).
            $discountRet  = pow($v, $tau);
            $discountExit = pow($v, $tau + 0.5);

            // Under PUC the indemnity is *attributed* to past service:
            // the PBO is the PV of the benefit earned for service rendered
            // before the measurement date. Standard straight-line
            // attribution = serviceNow / serviceAtExit (IAS 19 §70).
            $pastShare = $serviceAtT > 0 ? ($serviceNow / $serviceAtT) : 0.0;

            // PV contributions to the PBO (past-service slice).
            $expRet  = $pRet  * $indemnityRetirement  * $pastShare * $discountRet;
            $expTerm = $pTurn * $indemnityTermination * $pastShare * $discountExit;
            $expDth  = $pDth  * $indemnityDeath       * $pastShare * $discountExit;
            $pboRet   += $expRet;
            $pboTerm  += $expTerm;
            $pboDeath += $expDth;
            $pboTotal += $expRet + $expTerm + $expDth;

            // Expected benefit payment (regardless of past/future split)
            // bucketed by the calendar year the exit falls in (tau years
            // out) — used for the cash-flow projection.
            $year = (int) $valuationDate->copy()->addYears((int) round($tau))->year;
            $payByYear[$year] = ($payByYear[$year] ?? 0.0)
                + $pRet  * $indemnityRetirement
                + $pTurn * $indemnityTermination
                + $pDth  * $indemnityDeath;

            // Advance the cumulative active-survival probability to the
            // END of year t (sex-distinct mortality + turnover).
            if ($t < $yearsToRetirement) {
                $cumulativeActiveProb *= $this->decrements->activeSurvivalAt($ageAtT, $gender);
            }
        }

        // Service cost = PV of the benefit attributable to ONE
        // additional year of service after the measurement date.
        // Approximation: re-run the iteration with serviceNow
        // bumped by 1 year, take the increment.
        // To keep the engine simple + fast we compute it from the
        // first-derivative-style approximation: the benefit at
        // retirement, prorated for 1 more year of service, weighted
        // by P(active to retirement).
        $serviceCost = $this->approximateServiceCost(
            $e, $cba, $valuationDate, $ageInt, $serviceNow, $salaryNow,
            $yearsToRetirement, $n, $gender, $minServiceYears
        );

        // Interest cost is a PORTFOLIO roll-forward quantity (discount
        // rate × OPENING PBO) and is computed authoritatively by
        // ValuationRunner, which knows the prior period's closing PBO.
        // The per-employee figure here (closing PBO × rate) is indicative
        // only and is overridden at persistence time — the engine has no
        // "opening" notion for a single employee on a fresh measurement.
        $interestCost = $pboTotal * $this->discountRate;

        // Sort the year-payments and cap to a useful horizon.
        ksort($payByYear);

        return [
            'pbo'                          => round($pboTotal, 2),
            'service_cost'                 => round($serviceCost, 2),
            'interest_cost'                => round($interestCost, 2),
            'pbo_retirement'               => round($pboRet, 2),
            'pbo_termination'              => round($pboTerm, 2),
            'pbo_death'                    => round($pboDeath, 2),
            'expected_payments_by_year'    => array_map(fn ($v) => round($v, 2), $payByYear),
            'age_at_valuation'             => round($ageNow, 2),
            'service_years_at_valuation'   => round($serviceNow, 2),
            'monthly_salary_at_valuation'  => $salaryNow,
        ];
    }

    /**
     * Service cost approximation: PV of the indemnity at retirement
     * for one more year of service attributed to the upcoming year.
     */
    private function approximateServiceCost(
        Employee $e,
        array $cba,
        Carbon $valuationDate,
        int $ageInt,
        float $serviceNow,
        float $salaryNow,
        int $yearsToRetirement,
        float $n,
        ?string $gender,
        float $minServiceYears,
    ): float {
        if ($yearsToRetirement <= 0 || $n <= 0) return 0.0;

        // Salary + service at the EXACT retirement date (fractional n,
        // not the integer horizon — same fix as the PBO loop).
        $salaryAtRet  = $salaryNow * pow(1.0 + $this->salaryIncreaseRate, $n);
        $serviceAtRet = $serviceNow + $n;

        // If the employee never crosses the eligibility floor, no benefit
        // accrues, so there's no service cost.
        if ($serviceAtRet < $minServiceYears) return 0.0;

        // Indemnity at retirement assuming employee retires:
        $brackets = $cba['retirement_brackets'] ?? [];
        $indemnityRet = $salaryAtRet * CbaRegistry::applyBrackets($brackets, $serviceAtRet);

        // The "increment" from one more year of service is the
        // benefit divided by total service (proportional accrual).
        // This is the IAS 19 §70 approximation when the formula
        // is service-linear.
        $perYearAccrual = $serviceAtRet > 0 ? $indemnityRet / $serviceAtRet : 0.0;

        // Probability of reaching retirement (sex-distinct mortality +
        // turnover over the remaining integer ages).
        $pActive = $this->decrements->activeSurvivalBetween($ageInt, $ageInt + $yearsToRetirement, $gender);

        // Discount to the exact retirement date.
        $v = 1.0 / (1.0 + $this->discountRate);
        $discountToRet = pow($v, $n);

        return $perYearAccrual * $pActive * $discountToRet;
    }
}
