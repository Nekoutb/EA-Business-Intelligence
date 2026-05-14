<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Server-side KPI traffic-light scoring for the daily Business Insights.
 *
 * The Management Reporting skill specifies that executive reports must
 * lead with a one-page summary where each headline KPI carries a hard
 * traffic-light status (green / amber / red), NOT a narrative judgement.
 * The Business Analyst skill specifies that thresholds must be quantitative
 * and reproducible — not "the model felt this was bad."
 *
 * This scorer enforces both: it takes a KPI snapshot (the same shape
 * BusinessInsightsService aggregates) and returns a deterministic array
 * of headline KPIs with current value, prior value, variance, status,
 * and the threshold that triggered the status. The thresholds live here
 * so they can be reviewed, audited, and tuned by finance without touching
 * the model prompt.
 *
 * Headline KPI set (5 — fits a one-page exec summary):
 *
 *   1. Revenue YoY    — top-line growth signal
 *   2. Gross Margin   — operating efficiency
 *   3. EBITDA Margin  — earnings quality
 *   4. DSO            — cash conversion speed
 *   5. Overdue Rate   — collection health
 *
 * Output is folded into the saved `kpis_snapshot` JSON under the
 * `headline_kpis` key so the LLM can quote the exact same statuses
 * the UI shows, and historical rows automatically pick up new threshold
 * tunes when re-rendered.
 *
 * Thresholds (favourable direction matters — for cost-side KPIs like
 * DSO and Overdue Rate, lower = greener):
 *
 *   Revenue YoY %         green > +5    amber -5..+5    red < -5
 *   Gross Margin %        green > 35    amber 20..35    red < 20
 *   EBITDA Margin %       green > 10    amber  0..10    red < 0
 *   DSO (days)            green < 30    amber 30..60    red > 60
 *   Overdue Rate %        green < 10    amber 10..30    red > 30
 *
 * Forward-looking risk register — also derived from the snapshot using
 * the same thresholds plus a few "exposure" rules (AR > 90d > 15% of
 * total, billing realisation < 80%, etc). The risk register is what
 * appears in the show page's "Risk Register" panel and is referenced
 * by the prompt so the LLM doesn't invent risks.
 */
final class BusinessInsightsScorer
{
    public const STATUS_GREEN = 'green';
    public const STATUS_AMBER = 'amber';
    public const STATUS_RED   = 'red';
    public const STATUS_NA    = 'na';     // metric missing from snapshot

    /**
     * Score a snapshot and return the structured headline KPI block.
     *
     * Shape:
     *   [
     *     'as_of'   => '2026-05-14',
     *     'overall' => 'red' | 'amber' | 'green',     // worst of the 5
     *     'kpis'    => [
     *       'revenue_yoy' => [
     *         'label'      => 'Revenue YoY',
     *         'value'      => 1234567.0,             // raw current value
     *         'value_fmt'  => '+12.4%',              // formatted for display
     *         'prior'      => 1100000.0,             // prior period value
     *         'prior_fmt'  => 'prev 1,100,000',
     *         'variance'   => 134567.0,              // current - prior
     *         'variance_pct' => 12.23,
     *         'status'     => 'green' | 'amber' | 'red' | 'na',
     *         'threshold'  => 'green > +5%, amber -5%..+5%, red < -5%',
     *         'unit'       => '%',                    // %, $, days, count
     *         'direction'  => 'higher_better',        // or lower_better
     *       ],
     *       ...
     *     ],
     *     'risks' => [
     *       [
     *         'key'        => 'overdue_rate',
     *         'label'      => 'Overdue receivables',
     *         'severity'   => 'urgent' | 'watch',
     *         'value_fmt'  => '34%',
     *         'threshold'  => 'Triggered when > 30%',
     *         'recommendation' => '...',
     *       ],
     *       ...
     *     ],
     *   ]
     */
    public static function score(array $snapshot): array
    {
        $rev = is_array($snapshot['revenue']     ?? null) ? $snapshot['revenue']     : [];
        $pl  = is_array($snapshot['pl']          ?? null) ? $snapshot['pl']          : [];
        $ar  = is_array($snapshot['receivables'] ?? null) ? $snapshot['receivables'] : [];
        $rec = is_array($snapshot['reconciliation'] ?? null) ? $snapshot['reconciliation'] : [];

        $kpis = [
            'revenue_yoy'    => self::scoreRevenueYoy($rev),
            'gross_margin'   => self::scoreGrossMargin($pl),
            'ebitda_margin'  => self::scoreEbitdaMargin($pl),
            'dso'            => self::scoreDso($ar),
            'overdue_rate'   => self::scoreOverdueRate($ar),
        ];

        return [
            'as_of'    => (string) ($snapshot['as_of'] ?? date('Y-m-d')),
            'currency' => (string) ($snapshot['currency'] ?? ''),
            'overall'  => self::worstStatus(array_column($kpis, 'status')),
            'kpis'     => $kpis,
            'variance_table' => self::buildVarianceTable($rev, $pl),
            'risks'    => self::buildRiskRegister($rev, $pl, $ar, $rec),
        ];
    }

    // ── Headline KPI scoring ──────────────────────────────────────────

    private static function scoreRevenueYoy(array $rev): array
    {
        $yoy = $rev['yoy_pct'] ?? null;
        return [
            'label'        => 'Revenue YoY',
            'value'        => is_numeric($yoy) ? (float) $yoy : null,
            'value_fmt'    => is_numeric($yoy) ? self::pctSigned((float) $yoy) : '—',
            'prior'        => (float) ($rev['prev']['total'] ?? 0),
            'prior_fmt'    => is_numeric($rev['prev']['total'] ?? null)
                ? 'prev ' . self::amount((float) $rev['prev']['total'])
                : '—',
            'current_total' => (float) ($rev['total'] ?? 0),
            'current_fmt'  => is_numeric($rev['total'] ?? null)
                ? self::amount((float) $rev['total'])
                : '—',
            'variance'     => null,         // YoY is itself the variance
            'variance_pct' => is_numeric($yoy) ? (float) $yoy : null,
            'status'       => self::bandHigherBetter($yoy, 5.0, -5.0),
            'threshold'    => 'green > +5%, amber -5%..+5%, red < -5%',
            'unit'         => '%',
            'direction'    => 'higher_better',
        ];
    }

    private static function scoreGrossMargin(array $pl): array
    {
        $gm = $pl['gross_margin_pct'] ?? null;
        return [
            'label'        => 'Gross Margin',
            'value'        => is_numeric($gm) ? (float) $gm : null,
            'value_fmt'    => is_numeric($gm) ? self::pct((float) $gm) : '—',
            'prior'        => null,
            'prior_fmt'    => '—',
            'variance'     => null,
            'variance_pct' => null,
            'status'       => self::bandHigherBetter($gm, 35.0, 20.0),
            'threshold'    => 'green > 35%, amber 20%..35%, red < 20%',
            'unit'         => '%',
            'direction'    => 'higher_better',
        ];
    }

    private static function scoreEbitdaMargin(array $pl): array
    {
        $em = $pl['ebitda_margin_pct'] ?? null;
        return [
            'label'        => 'EBITDA Margin',
            'value'        => is_numeric($em) ? (float) $em : null,
            'value_fmt'    => is_numeric($em) ? self::pctSigned((float) $em) : '—',
            'prior'        => null,
            'prior_fmt'    => '—',
            'variance'     => null,
            'variance_pct' => null,
            'status'       => self::bandHigherBetter($em, 10.0, 0.0),
            'threshold'    => 'green > 10%, amber 0%..10%, red < 0%',
            'unit'         => '%',
            'direction'    => 'higher_better',
        ];
    }

    private static function scoreDso(array $ar): array
    {
        $d = $ar['dso'] ?? null;
        return [
            'label'        => 'DSO',
            'value'        => is_numeric($d) ? (float) $d : null,
            'value_fmt'    => is_numeric($d) ? self::days((float) $d) : '—',
            'prior'        => null,
            'prior_fmt'    => '—',
            'variance'     => null,
            'variance_pct' => null,
            'status'       => self::bandLowerBetter($d, 30.0, 60.0),
            'threshold'    => 'green < 30d, amber 30d..60d, red > 60d',
            'unit'         => 'days',
            'direction'    => 'lower_better',
        ];
    }

    private static function scoreOverdueRate(array $ar): array
    {
        $o = $ar['overdue_rate'] ?? null;
        return [
            'label'        => 'Overdue Rate',
            'value'        => is_numeric($o) ? (float) $o : null,
            'value_fmt'    => is_numeric($o) ? self::pct((float) $o) : '—',
            'prior'        => null,
            'prior_fmt'    => '—',
            'variance'     => null,
            'variance_pct' => null,
            'status'       => self::bandLowerBetter($o, 10.0, 30.0),
            'threshold'    => 'green < 10%, amber 10%..30%, red > 30%',
            'unit'         => '%',
            'direction'    => 'lower_better',
        ];
    }

    // ── Variance table (Management Reporting standard: Actual | Prior | Variance | %) ──

    private static function buildVarianceTable(array $rev, array $pl): array
    {
        $rows = [];

        // Top-line + drivers from the Revenue block (RpD / RpO / OpD / RpC / CpO)
        $cur  = $rev;
        $prv  = is_array($rev['prev'] ?? null) ? $rev['prev'] : [];
        foreach ([
            ['Revenue (total)', 'total', '$'],
            ['Revenue per day (RpD)', 'rpd', '$'],
            ['Revenue per outfit (RpO)', 'rpo', '$'],
            ['Outfits per day (OpD)', 'opd', 'count'],
            ['Revenue per customer (RpC)', 'rpc', '$'],
            ['Cost per outfit (CpO)', 'cpo', '$'],
        ] as [$label, $key, $unit]) {
            if (!array_key_exists($key, $cur)) continue;
            $rows[] = self::buildVarianceRow($label, $cur[$key] ?? null, $prv[$key] ?? null, $unit, 'higher_better');
        }

        // P&L doesn't carry a `prev` block in the trimmed snapshot, so
        // we can only show current absolutes. Still useful for the
        // narrative; flagged as "no comparison".
        foreach ([
            ['Gross profit', 'gross_profit', '$'],
            ['Operating expense', 'opex', '$'],
            ['EBITDA', 'ebitda', '$'],
            ['Net profit', 'net_profit', '$'],
        ] as [$label, $key, $unit]) {
            if (!array_key_exists($key, $pl)) continue;
            $rows[] = self::buildVarianceRow($label, $pl[$key] ?? null, null, $unit, 'higher_better');
        }

        return $rows;
    }

    private static function buildVarianceRow(string $label, $current, $prior, string $unit, string $direction): array
    {
        $cur = is_numeric($current) ? (float) $current : null;
        $prv = is_numeric($prior)   ? (float) $prior   : null;
        $var = ($cur !== null && $prv !== null) ? ($cur - $prv) : null;
        $pct = ($cur !== null && $prv !== null && abs($prv) > 0.0001) ? (($cur - $prv) / $prv * 100.0) : null;

        // Status of the variance — for "higher_better" metrics positive
        // variance is favourable, negative is adverse. Same band sizes
        // as Revenue YoY (±5%).
        $status = self::STATUS_NA;
        if ($pct !== null) {
            $favourable = $direction === 'higher_better' ? $pct : -$pct;
            if      ($favourable >=  5.0)  $status = self::STATUS_GREEN;
            elseif  ($favourable >= -5.0)  $status = self::STATUS_AMBER;
            else                           $status = self::STATUS_RED;
        }

        return [
            'label'        => $label,
            'current_fmt'  => $cur !== null
                ? ($unit === '$' ? self::amount($cur) : ($unit === '%' ? self::pct($cur) : self::count($cur)))
                : '—',
            'prior_fmt'    => $prv !== null
                ? ($unit === '$' ? self::amount($prv) : ($unit === '%' ? self::pct($prv) : self::count($prv)))
                : '—',
            'variance_fmt' => $var !== null
                ? ($unit === '$' ? self::amountSigned($var) : ($unit === '%' ? self::pctSigned($var) : self::countSigned($var)))
                : '—',
            'variance_pct_fmt' => $pct !== null ? self::pctSigned($pct) : '—',
            'status'       => $status,
            'unit'         => $unit,
        ];
    }

    // ── Risk register ─────────────────────────────────────────────────

    private static function buildRiskRegister(array $rev, array $pl, array $ar, array $rec): array
    {
        $risks = [];

        // Profitability — EBITDA negative is the loudest single signal.
        $em = $pl['ebitda_margin_pct'] ?? null;
        if (is_numeric($em) && (float) $em < 0) {
            $risks[] = [
                'key'      => 'ebitda_negative',
                'label'    => 'EBITDA negative',
                'severity' => 'urgent',
                'value_fmt'=> self::pctSigned((float) $em),
                'threshold'=> 'Triggered when < 0%',
                'recommendation' => 'Pause discretionary opex commitments this month; line-by-line cost review with the owner before any new spend.',
            ];
        }

        // Gross margin compression
        $gm = $pl['gross_margin_pct'] ?? null;
        if (is_numeric($gm) && (float) $gm < 20) {
            $risks[] = [
                'key'      => 'gross_margin_low',
                'label'    => 'Gross margin compression',
                'severity' => (float) $gm < 10 ? 'urgent' : 'watch',
                'value_fmt'=> self::pct((float) $gm),
                'threshold'=> 'Triggered when < 20%',
                'recommendation' => 'Re-quote top 5 customers; investigate CpO drivers (input prices, mix, rework).',
            ];
        }

        // Revenue contraction
        $yoy = $rev['yoy_pct'] ?? null;
        if (is_numeric($yoy) && (float) $yoy < -5) {
            $risks[] = [
                'key'      => 'revenue_decline',
                'label'    => 'Revenue contracting YoY',
                'severity' => (float) $yoy < -15 ? 'urgent' : 'watch',
                'value_fmt'=> self::pctSigned((float) $yoy),
                'threshold'=> 'Triggered when YoY < -5%',
                'recommendation' => 'Customer-level cohort review: who left, who shrank. Land 2–3 win-back conversations within 2 weeks.',
            ];
        }

        // Overdue receivables
        $ovd = $ar['overdue_rate'] ?? null;
        if (is_numeric($ovd) && (float) $ovd > 30) {
            $risks[] = [
                'key'      => 'overdue_rate',
                'label'    => 'Overdue receivables',
                'severity' => 'urgent',
                'value_fmt'=> self::pct((float) $ovd),
                'threshold'=> 'Triggered when > 30%',
                'recommendation' => 'Personal call to top 5 overdue accounts this week; tighten credit terms for new orders.',
            ];
        } elseif (is_numeric($ovd) && (float) $ovd > 10) {
            $risks[] = [
                'key'      => 'overdue_rate',
                'label'    => 'Overdue receivables building',
                'severity' => 'watch',
                'value_fmt'=> self::pct((float) $ovd),
                'threshold'=> 'Triggered when > 10%',
                'recommendation' => 'Run a chase batch on accounts 30–60 days overdue before they slip past 60.',
            ];
        }

        // AR aging > 90 days exposure (concentration risk)
        $gt90 = $ar['pct_gt_90'] ?? null;
        if (is_numeric($gt90) && (float) $gt90 > 15) {
            $risks[] = [
                'key'      => 'ar_gt_90',
                'label'    => 'Stale AR (>90 days) > 15% of total',
                'severity' => 'urgent',
                'value_fmt'=> self::pct((float) $gt90),
                'threshold'=> 'Triggered when > 15%',
                'recommendation' => 'Bad-debt provision review; consider write-off + collections agency for the worst offenders.',
            ];
        }

        // Working capital — DSO
        $dso = $ar['dso'] ?? null;
        if (is_numeric($dso) && (float) $dso > 60) {
            $risks[] = [
                'key'      => 'dso_high',
                'label'    => 'DSO > 60 days',
                'severity' => 'watch',
                'value_fmt'=> self::days((float) $dso),
                'threshold'=> 'Triggered when > 60 days',
                'recommendation' => 'Identify which terms are >30 days and renegotiate; offer 2/10 net 30 incentive on the next renewal cycle.',
            ];
        }

        // Billing leakage
        $bill = $rec['billing_realisation_pct'] ?? null;
        if (is_numeric($bill) && (float) $bill < 80) {
            $risks[] = [
                'key'      => 'billing_realisation',
                'label'    => 'Billing realisation < 80%',
                'severity' => 'urgent',
                'value_fmt'=> self::pct((float) $bill),
                'threshold'=> 'Triggered when < 80%',
                'recommendation' => 'Audit the gap between expected and billed revenue; check for un-invoiced deliveries or pricing errors.',
            ];
        }

        // Cash conversion
        $coll = $rec['collection_realisation_pct'] ?? null;
        if (is_numeric($coll) && (float) $coll < 75) {
            $risks[] = [
                'key'      => 'collection_realisation',
                'label'    => 'Collection realisation < 75%',
                'severity' => 'urgent',
                'value_fmt'=> self::pct((float) $coll),
                'threshold'=> 'Triggered when < 75%',
                'recommendation' => 'Cash-collection sprint: weekly call-down on every account > 30 days; offer payment plans where useful.',
            ];
        }

        // Concentration risk — top-5 customers = too much of AR
        $top5 = $ar['top5_pct'] ?? null;
        if (is_numeric($top5) && (float) $top5 > 60) {
            $risks[] = [
                'key'      => 'concentration_top5',
                'label'    => 'Customer concentration: top 5 = ' . self::pct((float) $top5) . ' of AR',
                'severity' => 'watch',
                'value_fmt'=> self::pct((float) $top5),
                'threshold'=> 'Triggered when top 5 > 60% of AR',
                'recommendation' => 'Diversify revenue: BD pipeline targeting 2 new mid-size accounts per quarter to reduce single-customer fragility.',
            ];
        }

        // Sort: urgent first, then watch.
        usort($risks, function ($a, $b) {
            $order = ['urgent' => 0, 'watch' => 1];
            return ($order[$a['severity']] ?? 2) <=> ($order[$b['severity']] ?? 2);
        });

        return $risks;
    }

    // ── Banding helpers ───────────────────────────────────────────────

    /** Higher-better metric: ≥ green-threshold → green, ≥ red-threshold → amber, else red. */
    private static function bandHigherBetter($value, float $greenAt, float $redAt): string
    {
        if (!is_numeric($value)) return self::STATUS_NA;
        $v = (float) $value;
        if ($v >= $greenAt) return self::STATUS_GREEN;
        if ($v >= $redAt)   return self::STATUS_AMBER;
        return self::STATUS_RED;
    }

    /** Lower-better metric: ≤ green-threshold → green, ≤ amber-cap → amber, else red. */
    private static function bandLowerBetter($value, float $greenAt, float $amberCap): string
    {
        if (!is_numeric($value)) return self::STATUS_NA;
        $v = (float) $value;
        if ($v <= $greenAt)  return self::STATUS_GREEN;
        if ($v <= $amberCap) return self::STATUS_AMBER;
        return self::STATUS_RED;
    }

    private static function worstStatus(array $statuses): string
    {
        if (in_array(self::STATUS_RED, $statuses, true))   return self::STATUS_RED;
        if (in_array(self::STATUS_AMBER, $statuses, true)) return self::STATUS_AMBER;
        if (in_array(self::STATUS_GREEN, $statuses, true)) return self::STATUS_GREEN;
        return self::STATUS_NA;
    }

    // ── Number formatters ─────────────────────────────────────────────

    private static function amount(float $v): string
    {
        // No currency symbol — the snapshot's `currency` field is shown
        // separately in the UI alongside the figures, and a single
        // formatter has to work for XAF, EUR, USD, etc.
        return number_format($v, 0, '.', ',');
    }
    private static function amountSigned(float $v): string
    {
        return ($v >= 0 ? '+' : '') . self::amount($v);
    }
    private static function pct(float $v): string
    {
        return number_format($v, 1) . '%';
    }
    private static function pctSigned(float $v): string
    {
        return ($v >= 0 ? '+' : '') . number_format($v, 1) . '%';
    }
    private static function days(float $v): string
    {
        return number_format($v, 0) . 'd';
    }
    private static function count(float $v): string
    {
        return number_format($v, 0, '.', ',');
    }
    private static function countSigned(float $v): string
    {
        return ($v >= 0 ? '+' : '') . number_format($v, 0, '.', ',');
    }

    // ── Tailwind class helpers ───────────────────────────────────────

    /**
     * Return Tailwind classes for a status pill — used by the views to
     * keep colour decisions in one place. Same green/amber/red/grey
     * scheme the rest of the admin uses.
     */
    public static function statusClasses(string $status): string
    {
        return match ($status) {
            self::STATUS_GREEN => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_AMBER => 'bg-amber-50 text-amber-700 border-amber-200',
            self::STATUS_RED   => 'bg-rose-50 text-rose-700 border-rose-200',
            default            => 'bg-slate-50 text-slate-500 border-slate-200',
        };
    }

    /**
     * Return Tailwind class for a solid dot indicator (the index page
     * uses these to render a row of 5 dots per tenant). Saturation a
     * little stronger so they read at small sizes.
     */
    public static function dotClass(string $status): string
    {
        return match ($status) {
            self::STATUS_GREEN => 'bg-emerald-500',
            self::STATUS_AMBER => 'bg-amber-500',
            self::STATUS_RED   => 'bg-rose-500',
            default            => 'bg-slate-300',
        };
    }
}
