<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\Valuation;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Mercer-style Excel workbook exporter.
 *
 * Produces a multi-sheet .xlsx mirroring the Mercer Globeleq
 * actuarial-valuation-report layout (one sheet per appendix):
 *
 *   1. Cover           — entity, plan name, valuation date, signer line
 *   2. Highlights      — current vs prior key metrics
 *   3. Roll-forward    — Appendix A
 *   4. Components NPBC — Appendix F
 *   5. Assumptions     — Appendix C
 *   6. Plan provisions — Appendix E (CBA bracket tables)
 *   7. Participants    — Appendix F headcount + payroll by category
 *   8. Cash flows      — Appendix M
 *   9. Sensitivity     — discount + salary shocks
 *
 * Designed to be opened next to the print-friendly HTML report —
 * the workbook is the "drill-into" companion, the HTML/PDF is
 * the deliverable.
 *
 * Implementation notes:
 *   - We build all rows up-front, then stream via openspout. The
 *     workbook is small (~100 rows total) so memory is not an
 *     issue even for thousand-employee populations.
 *   - The participant-detail rows live on a separate sheet that
 *     iterates the valuation_lines collection.
 *   - Styling is intentionally Mercer-plain: bold headers, single
 *     bottom border for totals, monospaced numeric column. No
 *     branded colors — auditors expect this look.
 */
final class ExcelReportExporter
{
    public function __construct(private readonly Valuation $valuation) {}

    public function streamDownload(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = sprintf(
            'post-retirement-valuation-%s.xlsx',
            $this->valuation->valuation_date->format('Y-m-d')
        );

        return response()->streamDownload(function () {
            $this->writeTo('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function writeTo(string $path): void
    {
        $writer = new Writer();
        $writer->openToFile($path);

        $bold = (new Style())->setFontBold();
        $title = (new Style())->setFontBold()->setFontSize(14);
        $header = (new Style())->setFontBold()->setBackgroundColor('EEEEEE');
        $right = (new Style())->setCellAlignment(CellAlignment::RIGHT);
        $totalLine = (new Style())->setFontBold()->setBorder(
            new Border(new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_MEDIUM, Border::STYLE_SOLID))
        );

        // ── Sheet 1: Cover ────────────────────────────────────────
        $writer->getCurrentSheet()->setName('Cover');
        $writer->getCurrentSheet()->setSheetView((new SheetView())->setShowGridLines(false));
        $tenant = $this->valuation->tenant;
        $writer->addRow(Row::fromValues(['POST-RETIREMENT BENEFITS — ACTUARIAL VALUATION REPORT'], $title));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Entity', $tenant?->brand ?: $tenant?->name ?: '—']));
        $writer->addRow(Row::fromValues(['Tenant slug', $tenant?->slug ?: '—']));
        $writer->addRow(Row::fromValues(['Valuation date', $this->valuation->valuation_date->format('d M Y')]));
        $writer->addRow(Row::fromValues(['Status', $this->valuation->status]));
        $writer->addRow(Row::fromValues(['Method', 'Projected Unit Credit (PUC)']));
        $writer->addRow(Row::fromValues(['Currency', 'XAF (CFA Franc BEAC)']));
        $writer->addRow(Row::fromValues(['Standards', 'SYSCOHADA REVISE 2017 · IAS 19']));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Sign-off']));
        $writer->addRow(Row::fromValues(['Prepared by', '________________________________']));
        $writer->addRow(Row::fromValues(['Date', '________________________________']));
        $writer->addRow(Row::fromValues(['Approved by', '________________________________']));
        $writer->addRow(Row::fromValues(['Date', '________________________________']));

        // ── Sheet 2: Highlights ───────────────────────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('Highlights');
        $writer->addRow(Row::fromValues(['Summary of results'], $title));
        $writer->addRow(Row::fromValues(['']));
        $prior = $this->valuation->priorValuation;
        $writer->addRow(Row::fromValues(['', 'Current period', 'Prior period'], $header));
        $writer->addRow(Row::fromValues([
            'Valuation date',
            $this->valuation->valuation_date->format('d M Y'),
            $prior?->valuation_date?->format('d M Y') ?? '—',
        ]));
        $writer->addRow(Row::fromValues([
            'PBO opening',
            (float) $this->valuation->pbo_opening,
            $prior ? (float) $prior->pbo_opening : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'Service cost',
            (float) $this->valuation->service_cost,
            $prior ? (float) $prior->service_cost : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'Interest cost',
            (float) $this->valuation->interest_cost,
            $prior ? (float) $prior->interest_cost : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'Benefit payments',
            (float) $this->valuation->benefit_payments,
            $prior ? (float) $prior->benefit_payments : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'Actuarial gain/(loss)',
            (float) $this->valuation->actuarial_gain_loss,
            $prior ? (float) $prior->actuarial_gain_loss : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'PBO closing',
            (float) $this->valuation->pbo_closing,
            $prior ? (float) $prior->pbo_closing : '—',
        ], $totalLine));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues([
            'Fair value of plan assets',
            (float) $this->valuation->fair_value_assets,
            $prior ? (float) $prior->fair_value_assets : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'Funded status',
            (float) $this->valuation->funded_status,
            $prior ? (float) $prior->funded_status : '—',
        ], $bold));
        $writer->addRow(Row::fromValues([
            'Net periodic benefit cost',
            (float) $this->valuation->net_periodic_benefit_cost,
            $prior ? (float) $prior->net_periodic_benefit_cost : '—',
        ]));
        $writer->addRow(Row::fromValues([
            'Liability duration (years)',
            (float) $this->valuation->duration_years,
            $prior ? (float) $prior->duration_years : '—',
        ]));

        // ── Sheet 3: Roll-forward (Appendix A) ────────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('A. Roll-forward');
        $writer->addRow(Row::fromValues(['Appendix A — Change in benefit obligation'], $title));
        $writer->addRow(Row::fromValues(['']));
        $rows = [
            ['1. Benefit obligation at beginning of period', (float) $this->valuation->pbo_opening],
            ['2. Service cost',                              (float) $this->valuation->service_cost],
            ['3. Interest cost',                             (float) $this->valuation->interest_cost],
            ['4. Benefit payments',                          -1 * (float) $this->valuation->benefit_payments],
            ['5. Actuarial (gain) / loss',                   (float) $this->valuation->actuarial_gain_loss],
            ['6. Plan amendments',                           (float) $this->valuation->plan_amendments],
            ['7. Settlements',                               (float) $this->valuation->settlements],
        ];
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->addRow(Row::fromValues([
            '8. Benefit obligation at end of period',
            (float) $this->valuation->pbo_closing,
        ], $totalLine));

        // ── Sheet 4: Components NPBC (Appendix F) ─────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('F. NPBC components');
        $writer->addRow(Row::fromValues(['Appendix F — Components of net periodic benefit cost'], $title));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['1. Service cost',                  (float) $this->valuation->service_cost]));
        $writer->addRow(Row::fromValues(['2. Interest cost',                 (float) $this->valuation->interest_cost]));
        $writer->addRow(Row::fromValues(['3. Expected return on plan assets', -1 * (float) $this->valuation->fair_value_assets * (float) ($this->valuation->assumptionSet?->expected_return_on_assets ?? 0)]));
        $writer->addRow(Row::fromValues([
            '4. Net periodic benefit cost',
            (float) $this->valuation->net_periodic_benefit_cost,
        ], $totalLine));

        // ── Sheet 5: Assumptions (Appendix C) ─────────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('C. Assumptions');
        $writer->addRow(Row::fromValues(['Appendix C — Actuarial Assumptions'], $title));
        $writer->addRow(Row::fromValues(['']));
        $a = $this->valuation->assumptionSet;
        if ($a) {
            $writer->addRow(Row::fromValues(['Discount rate',          $this->pct($a->discount_rate)]));
            $writer->addRow(Row::fromValues(['Salary increase rate',   $this->pct($a->salary_increase_rate)]));
            $writer->addRow(Row::fromValues(['Inflation rate',         $this->pct($a->inflation_rate)]));
            $writer->addRow(Row::fromValues(['Expected return on assets', $this->pct($a->expected_return_on_assets)]));
            $writer->addRow(Row::fromValues(['Mortality table',        strtoupper(str_replace('_', ' ', $a->mortality_table))]));
            $writer->addRow(Row::fromValues(['Retirement age',         (string) $a->retirement_age]));
            $writer->addRow(Row::fromValues(['Plan funded?',           $a->funded ? 'Yes' : 'No (unfunded)']));
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Turnover bands'], $header));
            $writer->addRow(Row::fromValues(['From age', 'To age', 'Annual rate'], $bold));
            foreach (($a->turnover_bands ?: []) as $b) {
                $writer->addRow(Row::fromValues([
                    (int) ($b['from_age'] ?? 0),
                    (int) ($b['to_age']   ?? 0),
                    $this->pct((float) ($b['rate'] ?? 0)),
                ]));
            }
        }

        // ── Sheet 6: Participants (Appendix F summary) ────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('F. Participants');
        $writer->addRow(Row::fromValues(['Appendix F — Participants Data'], $title));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Category', 'Headcount', 'Avg salary', 'PBO'], $header));
        $lines = $this->valuation->lines;
        foreach (['blue_collar' => 'Blue collars', 'supervisor' => 'Supervisors', 'manager' => 'Managers'] as $cat => $label) {
            $catLines = $lines->filter(fn ($l) => $l->employee && $l->employee->category === $cat);
            $writer->addRow(Row::fromValues([
                $label,
                $catLines->count(),
                $catLines->count() > 0 ? round($catLines->avg('monthly_salary_at_valuation'), 0) : 0,
                round($catLines->sum('pbo'), 0),
            ]));
        }
        $writer->addRow(Row::fromValues([
            'Total',
            $lines->count(),
            $lines->count() > 0 ? round($lines->avg('monthly_salary_at_valuation'), 0) : 0,
            round($lines->sum('pbo'), 0),
        ], $totalLine));

        // ── Sheet 7: Per-employee detail ──────────────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('Per employee');
        $writer->addRow(Row::fromValues(['Per-employee valuation lines'], $title));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues([
            'Code', 'Name', 'Category', 'Age', 'Service yrs', 'Monthly salary',
            'PBO', 'Service cost', 'Interest cost',
            'PBO retirement', 'PBO termination', 'PBO death',
        ], $header));
        foreach ($lines->sortByDesc('pbo') as $l) {
            $writer->addRow(Row::fromValues([
                $l->employee?->employee_code ?? '—',
                $l->employee?->full_name ?? '—',
                $l->employee?->category ?? '—',
                (float) $l->age_at_valuation,
                (float) $l->service_years_at_valuation,
                (float) $l->monthly_salary_at_valuation,
                (float) $l->pbo,
                (float) $l->service_cost,
                (float) $l->interest_cost,
                (float) $l->pbo_retirement,
                (float) $l->pbo_termination,
                (float) $l->pbo_death,
            ]));
        }

        // ── Sheet 8: Cash flows (Appendix M) ──────────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('M. Cash flows');
        $writer->addRow(Row::fromValues(['Appendix M — Expected benefit payments'], $title));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Year', 'Expected payment (XAF)'], $header));
        $payments = (array) ($this->valuation->payload['expected_payments_by_year'] ?? []);
        ksort($payments);
        foreach ($payments as $year => $amt) {
            $writer->addRow(Row::fromValues([(int) $year, round((float) $amt, 0)]));
        }

        // ── Sheet 9: Plan provisions (Appendix E) ─────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('E. Plan provisions');
        $writer->addRow(Row::fromValues(['Appendix E — Plan Provisions (CBA bracket schedules)'], $title));
        $writer->addRow(Row::fromValues(['']));
        // Take the CBA from the first line's employee
        $firstEmp = $lines->first()?->employee;
        $cba = $firstEmp ? CbaRegistry::forEmployee($firstEmp, $this->valuation->tenant) : null;
        if ($cba) {
            $writer->addRow(Row::fromValues([$cba['name']], $bold));
            $writer->addRow(Row::fromValues([$cba['source_doc'] ?? '']));
            $writer->addRow(Row::fromValues(['Minimum service months: ' . $cba['min_service_months']]));
            $writer->addRow(Row::fromValues(['']));
            foreach ([
                'termination_brackets' => 'Termination',
                'retirement_brackets'  => 'Retirement',
                'death_brackets'       => 'Death',
            ] as $key => $label) {
                $writer->addRow(Row::fromValues([$label . ' (% of avg monthly salary × years in band)'], $bold));
                $writer->addRow(Row::fromValues(['From year', 'To year', 'Rate'], $header));
                foreach ($cba[$key] ?? [] as $b) {
                    $writer->addRow(Row::fromValues([
                        (int) ($b['from'] ?? 0),
                        $b['to'] === null ? 'and above' : (int) $b['to'],
                        $this->pct((float) ($b['rate'] ?? 0)),
                    ]));
                }
                $writer->addRow(Row::fromValues(['']));
            }
        } else {
            $writer->addRow(Row::fromValues(['No CBA resolved — populate the employee roster first.']));
        }

        $writer->close();
    }

    private function pct(float $v): string
    {
        return number_format($v * 100, 2) . '%';
    }
}
