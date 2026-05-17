<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

/**
 * Combined decrements — mortality + turnover — by exact age.
 *
 * "Decrement" in actuarial speak = probability of an employee
 * leaving the active population in a given year. For pension
 * valuation we care about three decrements:
 *
 *   - retirement   (1 at retirement age, 0 elsewhere — i.e. a
 *                   deterministic event)
 *   - death        (from MortalityTable.qx)
 *   - turnover     (from the tenant's assumption set —
 *                   typically lower at older ages)
 *
 * Under the Projected Unit Credit method we need the *probability
 * that the indemnity will be paid for each scenario*. With three
 * mutually-exclusive scenarios, total decrement = 1 - (1-qx_death)
 * × (1-rate_turnover) and the share of each is allocated
 * proportionally to its individual probability.
 */
final class DecrementTable
{
    /**
     * @param array<int, array{from_age:int, to_age:int, rate:float}> $turnoverBands
     */
    public function __construct(
        private readonly array  $turnoverBands,
        private readonly string $mortalityTable,
        private readonly int    $retirementAge,
    ) {}

    /** Annual turnover rate for someone exact-age `$age`. */
    public function turnoverAt(int $age): float
    {
        foreach ($this->turnoverBands as $band) {
            $from = (int) ($band['from_age'] ?? 0);
            $to   = (int) ($band['to_age']   ?? 999);
            if ($age >= $from && $age < $to) {
                return (float) ($band['rate'] ?? 0);
            }
        }
        return 0.0;
    }

    /** Probability of dying in the year from age `$age` to age+1. */
    public function deathAt(int $age): float
    {
        return MortalityTable::qx($age, $this->mortalityTable);
    }

    /**
     * Probability that an employee aged `$age` survives one more
     * year as an active participant (i.e. doesn't die AND doesn't
     * leave voluntarily). Once you hit the retirement age, you
     * leave by definition → survival probability becomes 0.
     */
    public function activeSurvivalAt(int $age): float
    {
        if ($age >= $this->retirementAge) {
            return 0.0;
        }
        return (1.0 - $this->deathAt($age)) * (1.0 - $this->turnoverAt($age));
    }

    /**
     * Probability of remaining active in the plan from age `$from`
     * up to (but not including) age `$to`. Compounding of the
     * annual active-survival probabilities.
     */
    public function activeSurvivalBetween(int $from, int $to): float
    {
        if ($to <= $from) return 1.0;
        $p = 1.0;
        for ($age = $from; $age < $to; $age++) {
            $p *= $this->activeSurvivalAt($age);
        }
        return $p;
    }

    /**
     * Decompose the probability of leaving in year `$age → age+1`
     * by reason. Returns shares of the year's total decrement
     * allocated to each reason: retirement, death, turnover. The
     * shares sum to the total annual decrement probability for
     * that age (i.e. they're already probability-weighted, not
     * conditional probabilities).
     *
     * @return array{retirement:float, death:float, turnover:float}
     */
    public function decrementSharesAt(int $age): array
    {
        // At the exact retirement age we treat the entire annual
        // decrement as retirement — death and turnover happen during
        // the active period before retirement, not at it.
        if ($age >= $this->retirementAge) {
            return ['retirement' => 1.0, 'death' => 0.0, 'turnover' => 0.0];
        }
        $qd = $this->deathAt($age);
        $qt = $this->turnoverAt($age);
        // Total prob of leaving = 1 - prob of surviving both decrements.
        $total = 1.0 - (1.0 - $qd) * (1.0 - $qt);
        if ($total <= 0) {
            return ['retirement' => 0.0, 'death' => 0.0, 'turnover' => 0.0];
        }
        // Allocate proportionally to the marginal probabilities.
        // Sum of marginals (qd + qt) may slightly exceed `total`
        // (independence approximation); we normalise to the joint
        // total so the shares sum exactly.
        $denom = $qd + $qt;
        if ($denom <= 0) {
            return ['retirement' => 0.0, 'death' => 0.0, 'turnover' => 0.0];
        }
        return [
            'retirement' => 0.0,
            'death'      => $total * ($qd / $denom),
            'turnover'   => $total * ($qt / $denom),
        ];
    }
}
