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

        $ageInt = (int) floor($ageNow);
        // Cap projection horizon at retirement age. If somehow the
        // employee is already past retirement, the engine still
        // computes a PBO equal to the current accrued indemnity
        // (paid immediately).
        $yearsToRetirement = max(0, $this->retirementAge - $ageInt);

        $isRetiring = (bool) $e->entitled_to_cnps_pension;
        // Which bracket scale applies to each decrement.
        $retirementBrackets  = $cba['retirement_brackets']  ?? [];
        $terminationBrackets = $cba['termination_brackets'] ?? [];
        $deathBrackets       = $cba['death_brackets']       ?? [];

        // Phase-1 simplification: an employee NOT entitled to CNPS
        // still gets retirement-style indemnity at age 60 because
        // Article 46 CBA only requires the employer to recognize
        // their departure as retirement; the difference (no CNPS
        // pension) only matters externally. Both groups use the
        // retirement scale at retirement age.

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
            $ageAtT     = $ageInt + $t;
            $serviceAtT = $serviceNow + $t;
            $salaryAtT  = $salaryNow * pow($gSalary, $t);

            // Compute the indemnity that would be paid for each
            // possible exit at year t.
            $indemnityRetirement  = $salaryAtT * CbaRegistry::applyBrackets($retirementBrackets,  $serviceAtT);
            $indemnityTermination = $salaryAtT * CbaRegistry::applyBrackets($terminationBrackets, $serviceAtT);
            $indemnityDeath       = $salaryAtT * CbaRegistry::applyBrackets($deathBrackets,       $serviceAtT);

            // Probability of each scenario in year t (the
            // employee leaves during that year). At retirement
            // age (t === yearsToRetirement) the decrement is 100%
            // retirement; before, it's death + turnover only.
            if ($t === $yearsToRetirement) {
                // Retirement year — all remaining active prob goes here.
                $pRet  = $cumulativeActiveProb;
                $pDth  = 0.0;
                $pTurn = 0.0;
            } else {
                $shares = $this->decrements->decrementSharesAt($ageAtT);
                $pRet  = $cumulativeActiveProb * $shares['retirement'];
                $pDth  = $cumulativeActiveProb * $shares['death'];
                $pTurn = $cumulativeActiveProb * $shares['turnover'];
            }

            $discount = pow($v, $t);

            // Under PUC the indemnity is *attributed* to past
            // service — service rendered before the measurement
            // date vs after. The PBO is the PV of the benefit
            // earned for past service only.
            //
            // Standard simplification: attribute the indemnity
            // proportionally to (serviceNow / serviceAtT) — i.e.
            // the share of total service at exit that's already
            // been served by the measurement date. This matches
            // IAS 19 §72 and Mercer's Globeleq application.
            $pastShare = $serviceAtT > 0 ? ($serviceNow / $serviceAtT) : 0.0;
            $futureShare = 1.0 - $pastShare;

            // PV contributions to the PBO (past-service slice)
            $expRet  = $pRet  * $indemnityRetirement  * $pastShare * $discount;
            $expTerm = $pTurn * $indemnityTermination * $pastShare * $discount;
            $expDth  = $pDth  * $indemnityDeath       * $pastShare * $discount;
            $pboRet   += $expRet;
            $pboTerm  += $expTerm;
            $pboDeath += $expDth;
            $pboTotal += $expRet + $expTerm + $expDth;

            // Expected benefit payment (regardless of past/future
            // split) in year t — used for cash-flow projection
            // (Mercer Appendix M).
            $year = (int) $valuationDate->copy()->addYears($t)->year;
            $payByYear[$year] = ($payByYear[$year] ?? 0.0)
                + $pRet  * $indemnityRetirement
                + $pTurn * $indemnityTermination
                + $pDth  * $indemnityDeath;

            // Advance the cumulative active-survival probability
            // to the END of year t.
            if ($t < $yearsToRetirement) {
                $cumulativeActiveProb *= $this->decrements->activeSurvivalAt($ageAtT);
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
            $e, $cba, $valuationDate, $ageInt, $serviceNow, $salaryNow, $yearsToRetirement
        );

        // Interest cost = opening PBO × discount rate. Standard
        // IAS 19 / ASC 715 unwinding.
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
    ): float {
        if ($yearsToRetirement <= 0) return 0.0;

        // Salary at retirement
        $salaryAtRet = $salaryNow * pow(1.0 + $this->salaryIncreaseRate, $yearsToRetirement);
        // Total service at retirement
        $serviceAtRet = $serviceNow + $yearsToRetirement;

        // Indemnity at retirement assuming employee retires:
        $brackets = $cba['retirement_brackets'] ?? [];
        $indemnityRet = $salaryAtRet * CbaRegistry::applyBrackets($brackets, $serviceAtRet);

        // The "increment" from one more year of service is the
        // benefit divided by total service (proportional accrual).
        // This is the IAS 19 §70 approximation when the formula
        // is service-linear.
        $perYearAccrual = $serviceAtRet > 0 ? $indemnityRet / $serviceAtRet : 0.0;

        // Probability of reaching retirement (employee survives
        // mortality + turnover for the remaining years).
        $pActive = $this->decrements->activeSurvivalBetween($ageInt, $ageInt + $yearsToRetirement);

        // Discount one year (the cost is for the upcoming year,
        // so this is service cost not interest cost).
        $v = 1.0 / (1.0 + $this->discountRate);
        $discountToRet = pow($v, $yearsToRetirement);

        return $perYearAccrual * $pActive * $discountToRet;
    }
}
