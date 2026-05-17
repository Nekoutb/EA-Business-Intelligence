<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\AssumptionSet;
use App\Models\PostRetirementBenefits\Employee;
use App\Models\PostRetirementBenefits\Valuation;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Compute the PBO sensitivity to small movements in the discount
 * rate and salary increase rate.
 *
 * Standard actuarial disclosure: re-value the population with
 * each assumption shocked ±50 bp and ±100 bp, report the
 * resulting PBO. This is what tenants need to comply with IAS 19
 * paragraph 145 / SYSCOHADA's expectation that the actuarial
 * report disclose the sensitivity to "reasonably possible" shifts
 * in key assumptions.
 *
 * The analyzer doesn't touch the persisted Valuation row — it
 * runs the engine against the in-memory employee population for
 * each shocked rate and returns the totals.
 */
final class SensitivityAnalyzer
{
    /**
     * @param  array<int,float>  $rateShocks  e.g. [-0.01, -0.005, 0, +0.005, +0.01]
     * @return array<int, array{label:string, discount_rate:float, pbo:float, change:float, change_pct:float}>
     */
    public function discountRateSensitivity(
        Tenant $tenant,
        Valuation $baseline,
        array $rateShocks = [-0.01, -0.005, 0.005, 0.01]
    ): array {
        $assumptions = $baseline->assumptionSet;
        $employees = Employee::where('tenant_id', $tenant->id)->where('active', true)->get();
        $valDate = Carbon::parse($baseline->valuation_date);

        $rows = [];
        foreach ($rateShocks as $shock) {
            $newRate = (float) $assumptions->discount_rate + $shock;
            $engine = $this->buildEngine($assumptions, ['discount_rate' => $newRate]);
            $pbo = 0.0;
            foreach ($employees as $e) {
                $cba = CbaRegistry::forEmployee($e, $tenant);
                if (!$cba) continue;
                $r = $engine->valueEmployee($e, $cba, $valDate);
                $pbo += $r['pbo'];
            }
            $base = (float) $baseline->pbo_closing;
            $rows[] = [
                'label'         => $this->formatShockLabel($shock, 'discount_rate'),
                'discount_rate' => $newRate,
                'pbo'           => round($pbo, 2),
                'change'        => round($pbo - $base, 2),
                'change_pct'    => $base > 0 ? round(($pbo - $base) / $base * 100, 2) : 0,
            ];
        }
        return $rows;
    }

    /**
     * Same shape, applied to the salary increase assumption.
     */
    public function salaryIncreaseSensitivity(
        Tenant $tenant,
        Valuation $baseline,
        array $rateShocks = [-0.01, -0.005, 0.005, 0.01]
    ): array {
        $assumptions = $baseline->assumptionSet;
        $employees = Employee::where('tenant_id', $tenant->id)->where('active', true)->get();
        $valDate = Carbon::parse($baseline->valuation_date);

        $rows = [];
        foreach ($rateShocks as $shock) {
            $newRate = (float) $assumptions->salary_increase_rate + $shock;
            $engine = $this->buildEngine($assumptions, ['salary_increase_rate' => $newRate]);
            $pbo = 0.0;
            foreach ($employees as $e) {
                $cba = CbaRegistry::forEmployee($e, $tenant);
                if (!$cba) continue;
                $r = $engine->valueEmployee($e, $cba, $valDate);
                $pbo += $r['pbo'];
            }
            $base = (float) $baseline->pbo_closing;
            $rows[] = [
                'label'             => $this->formatShockLabel($shock, 'salary_increase'),
                'salary_increase'   => $newRate,
                'pbo'               => round($pbo, 2),
                'change'            => round($pbo - $base, 2),
                'change_pct'        => $base > 0 ? round(($pbo - $base) / $base * 100, 2) : 0,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, float> $overrides
     */
    private function buildEngine(AssumptionSet $assumptions, array $overrides = []): ProjectedUnitCreditEngine
    {
        $decrements = new DecrementTable(
            turnoverBands:  $assumptions->turnover_bands ?: [],
            mortalityTable: $assumptions->mortality_table ?: 'cima_f',
            retirementAge:  (int) $assumptions->retirement_age,
        );
        return new ProjectedUnitCreditEngine(
            decrements:         $decrements,
            discountRate:       $overrides['discount_rate']        ?? (float) $assumptions->discount_rate,
            salaryIncreaseRate: $overrides['salary_increase_rate'] ?? (float) $assumptions->salary_increase_rate,
            retirementAge:      (int) $assumptions->retirement_age,
        );
    }

    private function formatShockLabel(float $shock, string $what): string
    {
        if (abs($shock) < 1e-9) return 'baseline';
        $sign = $shock >= 0 ? '+' : '−';
        return $sign . number_format(abs($shock) * 100, 2) . 'pp';
    }
}
