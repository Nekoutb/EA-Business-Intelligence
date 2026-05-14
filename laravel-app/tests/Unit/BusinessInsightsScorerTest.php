<?php

namespace Tests\Unit;

use App\Services\BusinessInsightsScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Threshold lock-down for BusinessInsightsScorer.
 *
 * Tests the deterministic scoring band the UI + LLM both depend on. If
 * any of these bands change (e.g. finance decides EBITDA's amber band
 * should run -2% → +12% instead of 0% → +10%) bump the assertions here
 * in the same commit — that's how we prove the change was deliberate.
 *
 * Why this exists: the entire management-report UI hinges on this
 * scoring being correct. A regression here silently turns red KPIs
 * green or vice-versa on the index page without any test failing
 * elsewhere — the views just render whatever `status` says.
 */
class BusinessInsightsScorerTest extends TestCase
{
    #[DataProvider('headlineKpiCases')]
    public function test_headline_kpi_band_resolves_to_correct_status(
        string $caseName,
        array $snapshot,
        string $kpiKey,
        string $expectedStatus
    ): void {
        $scorecard = BusinessInsightsScorer::score($snapshot);
        $actual    = $scorecard['kpis'][$kpiKey]['status'] ?? 'missing';

        $this->assertSame(
            $expectedStatus,
            $actual,
            "Case '{$caseName}' expected {$kpiKey} status '{$expectedStatus}', got '{$actual}'."
        );
    }

    public static function headlineKpiCases(): array
    {
        // (case, snapshot, kpiKey, expectedStatus)
        return [
            // ── Revenue YoY (higher_better, green > +5%, amber -5..+5, red < -5)
            'YoY +10% → green'   => ['YoY +10%',  ['revenue' => ['yoy_pct' => 10.0]],  'revenue_yoy', 'green'],
            'YoY +5.01% → green' => ['YoY +5.01%',['revenue' => ['yoy_pct' => 5.01]],  'revenue_yoy', 'green'],
            'YoY +5% → green'    => ['YoY +5%',   ['revenue' => ['yoy_pct' => 5.0]],   'revenue_yoy', 'green'],
            'YoY 0% → amber'     => ['YoY 0%',    ['revenue' => ['yoy_pct' => 0.0]],   'revenue_yoy', 'amber'],
            'YoY -5% → amber'    => ['YoY -5%',   ['revenue' => ['yoy_pct' => -5.0]],  'revenue_yoy', 'amber'],
            'YoY -5.01% → red'   => ['YoY -5.01%',['revenue' => ['yoy_pct' => -5.01]], 'revenue_yoy', 'red'],
            'YoY -20% → red'     => ['YoY -20%',  ['revenue' => ['yoy_pct' => -20]],   'revenue_yoy', 'red'],
            'YoY missing → na'   => ['YoY missing', [],                                 'revenue_yoy', 'na'],

            // ── Gross Margin (higher_better, green > 35%, amber 20..35, red < 20)
            'GM 50% → green'      => ['GM 50%',  ['pl' => ['gross_margin_pct' => 50.0]], 'gross_margin', 'green'],
            'GM 35% → green'      => ['GM 35%',  ['pl' => ['gross_margin_pct' => 35.0]], 'gross_margin', 'green'],
            'GM 34.9% → amber'    => ['GM 34.9%',['pl' => ['gross_margin_pct' => 34.9]], 'gross_margin', 'amber'],
            'GM 20% → amber'      => ['GM 20%',  ['pl' => ['gross_margin_pct' => 20.0]], 'gross_margin', 'amber'],
            'GM 19.9% → red'      => ['GM 19.9%',['pl' => ['gross_margin_pct' => 19.9]], 'gross_margin', 'red'],
            'GM 0% → red'         => ['GM 0%',   ['pl' => ['gross_margin_pct' => 0]],    'gross_margin', 'red'],

            // ── EBITDA Margin (higher_better, green > 10%, amber 0..10, red < 0)
            'EBITDA 25% → green'  => ['EBITDA 25%', ['pl' => ['ebitda_margin_pct' => 25.0]], 'ebitda_margin', 'green'],
            'EBITDA 10% → green'  => ['EBITDA 10%', ['pl' => ['ebitda_margin_pct' => 10.0]], 'ebitda_margin', 'green'],
            'EBITDA 0% → amber'   => ['EBITDA 0%',  ['pl' => ['ebitda_margin_pct' => 0.0]],  'ebitda_margin', 'amber'],
            'EBITDA -0.01% → red' => ['EBITDA -0.01%',['pl'=>['ebitda_margin_pct'=>-0.01]],   'ebitda_margin', 'red'],
            'EBITDA -8% → red'    => ['EBITDA -8%', ['pl' => ['ebitda_margin_pct' => -8.0]], 'ebitda_margin', 'red'],

            // ── DSO (lower_better, green < 30, amber 30..60, red > 60)
            'DSO 15d → green'     => ['DSO 15d',['receivables' => ['dso' => 15]], 'dso', 'green'],
            'DSO 30d → green'     => ['DSO 30d',['receivables' => ['dso' => 30]], 'dso', 'green'],
            'DSO 30.1d → amber'   => ['DSO 30.1d',['receivables' => ['dso' => 30.1]], 'dso', 'amber'],
            'DSO 60d → amber'     => ['DSO 60d',['receivables' => ['dso' => 60]], 'dso', 'amber'],
            'DSO 60.1d → red'     => ['DSO 60.1d',['receivables' => ['dso' => 60.1]], 'dso', 'red'],
            'DSO 120d → red'      => ['DSO 120d',['receivables' => ['dso' => 120]], 'dso', 'red'],

            // ── Overdue Rate (lower_better, green < 10%, amber 10..30%, red > 30%)
            'OVD 5% → green'      => ['OVD 5%', ['receivables' => ['overdue_rate' => 5.0]], 'overdue_rate', 'green'],
            'OVD 10% → green'     => ['OVD 10%',['receivables' => ['overdue_rate' => 10.0]], 'overdue_rate', 'green'],
            'OVD 10.1% → amber'   => ['OVD 10.1%',['receivables' => ['overdue_rate' => 10.1]], 'overdue_rate', 'amber'],
            'OVD 30% → amber'     => ['OVD 30%',['receivables' => ['overdue_rate' => 30.0]], 'overdue_rate', 'amber'],
            'OVD 30.1% → red'     => ['OVD 30.1%',['receivables' => ['overdue_rate' => 30.1]], 'overdue_rate', 'red'],
            'OVD 50% → red'       => ['OVD 50%',['receivables' => ['overdue_rate' => 50.0]], 'overdue_rate', 'red'],
        ];
    }

    public function test_overall_status_is_worst_of_all_kpis(): void
    {
        // Three greens + one amber + one red → red (the worst wins).
        $snap = [
            'revenue' => ['yoy_pct' => 25.0],         // green
            'pl' => [
                'gross_margin_pct' => 45.0,           // green
                'ebitda_margin_pct' => 5.0,           // amber
            ],
            'receivables' => [
                'dso' => 20,                          // green
                'overdue_rate' => 45.0,               // red
            ],
        ];
        $score = BusinessInsightsScorer::score($snap);
        $this->assertSame('red', $score['overall']);
    }

    public function test_overall_is_amber_when_no_red_present(): void
    {
        $snap = [
            'revenue' => ['yoy_pct' => 10.0],
            'pl' => ['gross_margin_pct' => 40.0, 'ebitda_margin_pct' => 5.0],
            'receivables' => ['dso' => 25, 'overdue_rate' => 8.0],
        ];
        $this->assertSame('amber', BusinessInsightsScorer::score($snap)['overall']);
    }

    public function test_overall_is_green_when_every_kpi_passes(): void
    {
        $snap = [
            'revenue' => ['yoy_pct' => 25.0],
            'pl' => ['gross_margin_pct' => 50.0, 'ebitda_margin_pct' => 18.0],
            'receivables' => ['dso' => 22, 'overdue_rate' => 5.0],
        ];
        $this->assertSame('green', BusinessInsightsScorer::score($snap)['overall']);
    }

    /**
     * The risk register must fire for every threshold-triggered condition.
     * This case sets every risk to "urgent" simultaneously — the scorer
     * should produce one risk row per trigger, sorted urgent-first.
     */
    public function test_risk_register_fires_for_all_threshold_breaches(): void
    {
        $snap = [
            'revenue' => ['yoy_pct' => -25.0],   // revenue decline (urgent, < -15)
            'pl' => [
                'gross_margin_pct'  => 8.0,      // GM compression (urgent, < 10)
                'ebitda_margin_pct' => -5.0,     // EBITDA negative (urgent)
            ],
            'receivables' => [
                'overdue_rate' => 45.0,          // overdue (urgent, > 30)
                'pct_gt_90'    => 22.0,          // stale AR (urgent, > 15)
                'dso'          => 75,            // DSO > 60 (watch)
                'top5_pct'     => 70.0,          // concentration (watch, > 60)
            ],
            'reconciliation' => [
                'billing_realisation_pct'    => 70.0,  // billing leak (urgent, < 80)
                'collection_realisation_pct' => 50.0,  // cash conv (urgent, < 75)
            ],
        ];
        $risks = BusinessInsightsScorer::score($snap)['risks'];

        $keys = array_column($risks, 'key');
        $expected = [
            'ebitda_negative', 'gross_margin_low', 'revenue_decline', 'overdue_rate',
            'ar_gt_90', 'billing_realisation', 'collection_realisation',
            'dso_high', 'concentration_top5',
        ];
        foreach ($expected as $k) {
            $this->assertContains($k, $keys, "Risk '{$k}' should be triggered");
        }

        // Urgent risks must all come before watch risks.
        $severities = array_column($risks, 'severity');
        $firstWatchIdx = array_search('watch', $severities, true);
        if ($firstWatchIdx !== false) {
            $beforeWatch = array_slice($severities, 0, $firstWatchIdx);
            foreach ($beforeWatch as $sev) {
                $this->assertSame('urgent', $sev, 'Watch risks must follow urgent risks in the sorted register.');
            }
        }
    }

    public function test_empty_snapshot_returns_na_kpis_and_no_risks(): void
    {
        $score = BusinessInsightsScorer::score([]);

        foreach (['revenue_yoy', 'gross_margin', 'ebitda_margin', 'dso', 'overdue_rate'] as $k) {
            $this->assertSame('na', $score['kpis'][$k]['status'], "{$k} should be na on empty snapshot");
        }
        $this->assertSame('na', $score['overall']);
        $this->assertSame([], $score['risks']);
    }

    public function test_variance_table_computes_correct_pct_change(): void
    {
        $snap = [
            'revenue' => [
                'total' => 1_200_000.0,
                'rpd'   => 24_000.0,
                'rpo'   => 100.0,
                'opd'   => 12.0,
                'rpc'   => 1_500.0,
                'cpo'   => 38.0,
                'prev'  => [
                    'total' => 1_000_000.0,  // +20% favourable → green
                    'rpd'   => 20_000.0,     // +20%
                    'rpo'   => 95.0,         // +5.26% → green (>5%)
                    'opd'   => 12.0,         // 0% → amber
                    'rpc'   => 1_400.0,      // +7.14% → green
                    'cpo'   => 35.0,         // higher_better metric assumed; +8.57% — actually CpO is treated as higher_better here in builder; that's fine, the column shows the figures faithfully
                ],
            ],
        ];
        $table = BusinessInsightsScorer::score($snap)['variance_table'];

        // Find the "Revenue (total)" row
        $totalRow = collect($table)->firstWhere('label', 'Revenue (total)');
        $this->assertNotNull($totalRow);
        $this->assertSame('+20.0%', $totalRow['variance_pct_fmt']);
        $this->assertSame('green', $totalRow['status']);

        $opdRow = collect($table)->firstWhere('label', 'Outfits per day (OpD)');
        $this->assertNotNull($opdRow);
        $this->assertSame('+0.0%', $opdRow['variance_pct_fmt']);
        $this->assertSame('amber', $opdRow['status']);
    }
}
