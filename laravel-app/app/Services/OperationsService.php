<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Operations service — container & logistics analytics.
 *
 * Sourced from the customer's custom AW Cargo Odoo module ("Logistique"
 * menu) which models a maritime port operation:
 *
 *   aw_cargo.stopover   (Escales)    — vessel calls (ship + voyage + ETA/ETD)
 *   aw_cargo.cargo      (Cargaisons) — bills of lading / shipments
 *   aw_cargo.equipment  (Conteneurs) — individual containers, with the
 *                                       scan/inspection/gate-check flags
 *                                       that drive most operational KPIs
 *
 * The schema was discovered via /admin/tenants/{id}/odoo-probe (PR #13).
 *
 * KPI shape contract — each helper returns:
 *   [
 *     'value'   => string,   // formatted number / percentage / breakdown
 *     'sub'     => string,   // optional sub-text (denominator detail)
 *     'pending' => bool,     // false = live data; true = no schema / error
 *     'vsPy'    => ?float,   // % vs prior-year same window (when available)
 *     'error'   => ?string,  // present when an Odoo call threw
 *   ]
 *
 * Period anchor: every KPI uses `create_date` as the period filter
 * because:
 *   - aw_cargo.stopover.eta is often null in the data (sample showed
 *     2 of 2 records with eta=false)
 *   - aw_cargo.cargo.eta same story
 *   - create_date is set by Odoo automatically and approximates "when
 *     this container/voyage was registered in the system" which is the
 *     closest proxy to "handled this period"
 *
 * If a future deploy wants ETA-based filtering once the data quality
 * improves, swap the field name in the `periodDomain()` calls below.
 */
final class OperationsService
{
    private OdooClient $odoo;

    /** Custom Odoo models from the AW Cargo module. */
    private const MODEL_STOPOVER  = 'aw_cargo.stopover';
    private const MODEL_CARGO     = 'aw_cargo.cargo';
    private const MODEL_EQUIPMENT = 'aw_cargo.equipment';

    /**
     * Scanning price grid (FCFA, HT — pre-tax) per the customer's official
     * "GRILLE TARIFAIRE" (Transatlantic D, La Direction Générale).
     *
     * Important rules baked in below:
     *   - Transit containers (any size) are scanned free (systematic check)
     *   - Empty containers (any size) are scanned free
     *   - 45' is treated the same as 40' (the grid only enumerates 20"/40";
     *     45' is the same physical handling cost so we map it to the
     *     40' tariff)
     *
     * If the firm later wants TTC (after VAT) instead of HT, multiply by
     * the published TTC ratio (~1.1925 for the 20'/40' rows on the grid).
     */
    private const PRICE_HT_EXPORT_FULL_20    = 38_500;
    private const PRICE_HT_EXPORT_FULL_40    = 50_000;
    private const PRICE_HT_IMPORT_FULL_20    = 66_000;
    private const PRICE_HT_IMPORT_FULL_40    = 95_000;

    // "Autres" pricing (non-container scans).
    //
    //   Vehicle classification — discovered via deep probe v3:
    //     aw_vehicule.vehicule.cylinders mirrors the `size` codes used in
    //     aw_cargo_invoice.product (the official billing legend), giving
    //     us a clean four-way split:
    //
    //       cylinders ∈ {4, 6}  → Voiture (size 4 = small, size 6 = larger)
    //       cylinders == 8       → CAMION / TRUCK (size 8 in the invoice grid)
    //       cylinders == 12      → BULLDOZE / heavy machinery (size 12)
    //       cylinders ∉ above   → unclassified — mostly *non-vehicle* cargo
    //                             (the table also stores rows like "CARTONS",
    //                             "SACS", "COOKWARE", "X40'HC" — see probe).
    //                             We do NOT bill the residual; it's flagged
    //                             so the count is visible without inflating
    //                             revenue.
    //
    //   Rates come straight from the customer's Grille Tarifaire. They are
    //   editable per scenario in the Expected Revenue Simulation block on
    //   the page, but these constants are the canonical defaults.
    private const PRICE_HT_VOITURE           = 50_000;
    private const PRICE_HT_CAMION            = 95_000;
    private const PRICE_HT_BULLDOZE          = 105_000;
    private const PRICE_HT_WOOD_PER_TRUCK    = 35_000;

    /**
     * Cap on the number of records pulled per period for time-to-scan
     * analytics. Above this we sample the most-recent N (`order desc`).
     * 10K rows × 2 datetime fields ≈ ~400 KB transfer, fits in 1-2 sec
     * on the live network.
     */
    private const SCAN_TIME_SAMPLE_CAP = 10_000;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);

        $pyFrom = $cyFrom->modify('-1 year');
        $pyTo   = $cyTo->modify('-1 year');

        $cyWd = $this->workingDays($cyFrom, $cyTo);
        $pyWd = $this->workingDays($pyFrom, $pyTo);

        // Time-to-scan analytics — fetched once per period and shared
        // across the two efficiency KPIs (medianTimeToScan + sameDayScan)
        // so we don't double-charge the network for the same data.
        $cyScanTimes = $this->fetchScanTimes($cyFrom, $cyTo);
        $pyScanTimes = $this->fetchScanTimes($pyFrom, $pyTo);

        // Revenue breakdown — fetched once and exposed both as a headline
        // KPI ('scan_revenue') and as the `revenue_breakdown` table at the
        // bottom of the page.
        $cyRevenue = $this->scanRevenueBreakdown($cyFrom, $cyTo);
        $pyRevenue = $this->scanRevenueBreakdown($pyFrom, $pyTo);

        // Each helper traps its own exceptions and returns pending=true on
        // failure, so a single broken query never takes down the whole page.
        $kpis = [
            // ── Throughput ───────────────────────────────────────────────
            'vessel_calls'        => $this->vesselCalls($cyFrom, $cyTo, $pyFrom, $pyTo),
            'containers_handled'  => $this->containersHandled($cyFrom, $cyTo, $pyFrom, $pyTo),
            'containers_per_day'  => $this->containersPerDay($cyFrom, $cyTo, $cyWd, $pyFrom, $pyTo, $pyWd),

            // ── Scan & Quality ───────────────────────────────────────────
            'containers_scanned'  => $this->containersScanned($cyFrom, $cyTo, $pyFrom, $pyTo),
            'scan_rate'           => $this->scanRate($cyFrom, $cyTo, $pyFrom, $pyTo),
            'inspection_rate'     => $this->inspectionRate($cyFrom, $cyTo, $pyFrom, $pyTo),
            'median_time_to_scan' => $this->medianTimeToScan($cyScanTimes, $pyScanTimes),
            'same_day_scan_rate'  => $this->sameDayScanRate($cyScanTimes, $pyScanTimes),

            // ── Mix ──────────────────────────────────────────────────────
            'mix_by_size'         => $this->mixBySize($cyFrom, $cyTo),
            'full_empty_mix'      => $this->fullEmptyMix($cyFrom, $cyTo),
            'direction_mix'       => $this->directionMix($cyFrom, $cyTo),

            // ── Scan Revenue ─────────────────────────────────────────────
            'scan_revenue'        => $this->scanRevenue($cyRevenue, $pyRevenue),
        ];

        // Trend chart series — granularity follows period:
        //   YTD selected → monthly buckets across the year so far
        //   MTD selected → weekly buckets within the current month
        // Series for every KPI that has a clean per-bucket aggregation
        // (counts and revenue; medians/percentiles need a bigger fetch
        // and are deferred to a v2 of this method).
        $trend = $this->buildTrend($cyFrom, $cyTo, $period);

        return [
            'currency' => $this->detectCurrency(),
            'period'   => [
                'from'            => $cyFrom->format('Y-m-d'),
                'to'              => $cyTo->format('Y-m-d'),
                'label'           => $periodLabel,
                'cy_working_days' => $cyWd,
                'py_working_days' => $pyWd,
            ],
            'kpis'              => $kpis,
            'revenue_breakdown' => $cyRevenue,
            'trend'             => $trend,
            'pending_overall'   => collect($kpis)->every(fn ($k) => !empty($k['pending'])),
        ];
    }

    /* ── Trend chart ──────────────────────────────────────────────────── */

    /**
     * Build the per-bucket time series for the chart below the KPIs.
     *
     * Granularity:
     *   - YTD → monthly (Jan, Feb, …, current month)
     *   - MTD → weekly (W1, W2, W3, W4, W5)
     *
     * Series produced:
     *   - containers_handled    : count(equipment) bucketed by create_date
     *   - containers_scanned    : count(scanned=true) bucketed by scanned_date
     *   - scan_rate             : scanned ÷ handled per bucket (×100)
     *   - scan_revenue          : per-bucket revenue from the price grid
     *   - vessel_calls          : count(stopover) bucketed by create_date
     *
     * @return array{granularity:string, buckets:array<int,array{key:string,label:string,from:string,to:string}>, series:array<string,array<int,float>>, meta:array<string,array{label:string,unit:string,format:string}>, error:?string}
     */
    private function buildTrend(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, array $period): array
    {
        $type = $period['type'] ?? 'ytd';
        $granularity = $type === 'mtd' ? 'week' : 'month';

        try {
            $buckets = $this->trendBuckets($cyFrom, $cyTo, $granularity);

            $series = [
                'containers_handled' => [],
                'containers_scanned' => [],
                'scan_rate'          => [],
                'scan_revenue'       => [],
                'vessel_calls'       => [],
            ];

            foreach ($buckets as $b) {
                $bFrom = new \DateTimeImmutable($b['from']);
                $bTo   = new \DateTimeImmutable($b['to']);

                // Containers handled — count equipment created in bucket.
                $handled = $this->odoo->searchCount(self::MODEL_EQUIPMENT, [
                    ['create_date', '>=', $bFrom->format('Y-m-d 00:00:00')],
                    ['create_date', '<=', $bTo->format('Y-m-d 23:59:59')],
                    ['active', '=', true],
                ]);
                $series['containers_handled'][] = (float) $handled;

                // Containers scanned — count scans by scanned_date.
                $scanned = $this->odoo->searchCount(self::MODEL_EQUIPMENT, [
                    ['scanned', '=', true],
                    ['scanned_date', '>=', $bFrom->format('Y-m-d 00:00:00')],
                    ['scanned_date', '<=', $bTo->format('Y-m-d 23:59:59')],
                ]);
                $series['containers_scanned'][] = (float) $scanned;

                // Scan rate — scanned/handled. Note the basis: we want
                // "of containers handled in bucket, how many got scanned
                // (regardless of when)". Reusing handled denominator.
                // For chart purposes use scanned-in-bucket / handled-in-bucket
                // so the rate moves with current operational tempo.
                $series['scan_rate'][] = $handled > 0 ? round(100.0 * $scanned / $handled, 1) : 0.0;

                // Vessel calls — same bucket window.
                $stopovers = $this->odoo->searchCount(self::MODEL_STOPOVER, [
                    ['create_date', '>=', $bFrom->format('Y-m-d 00:00:00')],
                    ['create_date', '<=', $bTo->format('Y-m-d 23:59:59')],
                    ['active', '=', true],
                ]);
                $series['vessel_calls'][] = (float) $stopovers;

                // Revenue — apply price grid to bucketed scan mix.
                $rev = $this->scanRevenueBreakdown($bFrom, $bTo);
                $series['scan_revenue'][] = (float) ($rev['total_revenue'] ?? 0);
            }

            return [
                'granularity' => $granularity,
                'buckets'     => $buckets,
                'series'      => $series,
                'meta' => [
                    'containers_handled' => ['label' => 'Containers Handled', 'format' => 'count', 'unit' => ''],
                    'containers_scanned' => ['label' => 'Containers Scanned', 'format' => 'count', 'unit' => ''],
                    'scan_rate'          => ['label' => 'Scan Rate',          'format' => 'pct',   'unit' => '%'],
                    'scan_revenue'       => ['label' => 'Expected Revenue',   'format' => 'money', 'unit' => 'XAF'],
                    'vessel_calls'       => ['label' => 'Vessel Calls',       'format' => 'count', 'unit' => ''],
                ],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'granularity' => $granularity,
                'buckets'     => [],
                'series'      => [],
                'meta'        => [],
                'error'       => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate evenly-spaced time buckets from $from to $to.
     *
     * Monthly: each calendar month touched by [from, to], clamped to the
     *          actual period boundaries. Labels Jan / Feb / … / Dec.
     * Weekly:  ISO weeks within the period. Labels W1 / W2 / … relative
     *          to the start of the month (or absolute ISO week if YTD
     *          ever drops to weekly).
     *
     * @return array<int,array{key:string,label:string,from:string,to:string}>
     */
    private function trendBuckets(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity): array
    {
        $buckets = [];

        if ($granularity === 'month') {
            $cursor = new \DateTimeImmutable($from->format('Y-m-01 00:00:00'));
            while ($cursor <= $to) {
                $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);
                $bFrom = $cursor < $from ? $from : $cursor;
                $bTo   = $monthEnd > $to ? $to : $monthEnd;
                $buckets[] = [
                    'key'   => $cursor->format('Y-m'),
                    'label' => $cursor->format('M'),
                    'from'  => $bFrom->format('Y-m-d 00:00:00'),
                    'to'    => $bTo->format('Y-m-d 23:59:59'),
                ];
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0, 0);
            }
        } else { // week
            // Anchor weeks to the start of the month so MTD reads as W1..W5
            // rather than ISO weeks (which would jump mid-period).
            $monthStart = new \DateTimeImmutable($from->format('Y-m-01 00:00:00'));
            $cursor = $monthStart;
            $weekIndex = 1;
            while ($cursor <= $to) {
                $weekEnd = $cursor->modify('+6 days')->setTime(23, 59, 59);
                $bFrom = $cursor < $from ? $from : $cursor;
                $bTo   = $weekEnd > $to ? $to : $weekEnd;
                if ($bFrom <= $bTo) {
                    $buckets[] = [
                        'key'   => $cursor->format('Y-m-d'),
                        'label' => 'W' . $weekIndex,
                        'from'  => $bFrom->format('Y-m-d 00:00:00'),
                        'to'    => $bTo->format('Y-m-d 23:59:59'),
                    ];
                }
                $cursor = $cursor->modify('+7 days')->setTime(0, 0, 0);
                $weekIndex++;
            }
        }

        return $buckets;
    }

    /* ── KPI helpers ─────────────────────────────────────────────────── */

    private function vesselCalls(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        try {
            $cy = $this->odoo->searchCount(self::MODEL_STOPOVER, $this->stdDomain($cyFrom, $cyTo));
            $py = $this->odoo->searchCount(self::MODEL_STOPOVER, $this->stdDomain($pyFrom, $pyTo));

            return [
                'value'   => number_format($cy),
                'sub'     => 'Escales (vessel calls)',
                'pending' => false,
                'vsPy'    => $this->pct((float) $cy, (float) $py),
            ];
        } catch (\Throwable $e) {
            return $this->failed('Escales (vessel calls)', $e);
        }
    }

    private function containersHandled(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        try {
            $cy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($cyFrom, $cyTo));
            $py = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($pyFrom, $pyTo));

            return [
                'value'   => number_format($cy),
                'sub'     => 'Containers in period',
                'pending' => false,
                'vsPy'    => $this->pct((float) $cy, (float) $py),
            ];
        } catch (\Throwable $e) {
            return $this->failed('Containers in period', $e);
        }
    }

    private function containersPerDay(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, int $cyWd, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo, int $pyWd): array
    {
        try {
            $cy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($cyFrom, $cyTo));
            $py = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($pyFrom, $pyTo));

            $cyRate = $cyWd > 0 ? $cy / $cyWd : 0.0;
            $pyRate = $pyWd > 0 ? $py / $pyWd : 0.0;

            return [
                'value'   => number_format($cyRate, 1),
                'sub'     => "Throughput / working day · {$cyWd} WD",
                'pending' => false,
                'vsPy'    => $this->pct($cyRate, $pyRate),
            ];
        } catch (\Throwable $e) {
            return $this->failed("Throughput / working day", $e);
        }
    }

    private function containersScanned(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        try {
            // Note: filter on `scanned_date` (when the scan happened), not
            // create_date, so this is "scanned in this period" regardless of
            // when the container record was first registered.
            $cy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, [
                ['scanned', '=', true],
                ['scanned_date', '>=', $cyFrom->format('Y-m-d 00:00:00')],
                ['scanned_date', '<=', $cyTo->format('Y-m-d 23:59:59')],
            ]);
            $py = $this->odoo->searchCount(self::MODEL_EQUIPMENT, [
                ['scanned', '=', true],
                ['scanned_date', '>=', $pyFrom->format('Y-m-d 00:00:00')],
                ['scanned_date', '<=', $pyTo->format('Y-m-d 23:59:59')],
            ]);

            return [
                'value'   => number_format($cy),
                'sub'     => 'Scanned in period',
                'pending' => false,
                'vsPy'    => $this->pct((float) $cy, (float) $py),
            ];
        } catch (\Throwable $e) {
            return $this->failed('Scanned in period', $e);
        }
    }

    private function scanRate(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        try {
            $totalCy   = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($cyFrom, $cyTo));
            $scannedCy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, array_merge(
                $this->stdDomain($cyFrom, $cyTo),
                [['scanned', '=', true]]
            ));
            $totalPy   = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($pyFrom, $pyTo));
            $scannedPy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, array_merge(
                $this->stdDomain($pyFrom, $pyTo),
                [['scanned', '=', true]]
            ));

            $cyPct = $totalCy > 0 ? 100.0 * $scannedCy / $totalCy : 0.0;
            $pyPct = $totalPy > 0 ? 100.0 * $scannedPy / $totalPy : 0.0;

            return [
                'value'   => number_format($cyPct, 1) . '%',
                'sub'     => number_format($scannedCy) . ' of ' . number_format($totalCy),
                'pending' => false,
                'vsPy'    => $cyPct - $pyPct,  // pp delta (signed)
            ];
        } catch (\Throwable $e) {
            return $this->failed('Scan rate', $e);
        }
    }

    private function inspectionRate(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        try {
            $totalCy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($cyFrom, $cyTo));
            $inspectedCy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, array_merge(
                $this->stdDomain($cyFrom, $cyTo),
                [['inspected', '!=', 'not_done']]
            ));
            $totalPy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, $this->stdDomain($pyFrom, $pyTo));
            $inspectedPy = $this->odoo->searchCount(self::MODEL_EQUIPMENT, array_merge(
                $this->stdDomain($pyFrom, $pyTo),
                [['inspected', '!=', 'not_done']]
            ));

            $cyPct = $totalCy > 0 ? 100.0 * $inspectedCy / $totalCy : 0.0;
            $pyPct = $totalPy > 0 ? 100.0 * $inspectedPy / $totalPy : 0.0;

            return [
                'value'   => number_format($cyPct, 1) . '%',
                'sub'     => number_format($inspectedCy) . ' of ' . number_format($totalCy),
                'pending' => false,
                'vsPy'    => $cyPct - $pyPct,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Inspection rate', $e);
        }
    }

    /**
     * Pull (scanned_date, create_date) pairs for containers scanned in the
     * given period. Capped at SCAN_TIME_SAMPLE_CAP and ordered by most-
     * recent so the sample is representative of current operational
     * performance, not historical.
     *
     * @return array{rows: array<int,array{scanned_date:string,create_date:string}>, capped: bool, error: ?string}
     */
    private function fetchScanTimes(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            $rows = $this->odoo->searchRead(
                self::MODEL_EQUIPMENT,
                [
                    ['scanned', '=', true],
                    ['scanned_date', '>=', $from->format('Y-m-d 00:00:00')],
                    ['scanned_date', '<=', $to->format('Y-m-d 23:59:59')],
                ],
                ['scanned_date', 'create_date'],
                ['limit' => self::SCAN_TIME_SAMPLE_CAP, 'order' => 'scanned_date desc']
            );
            return [
                'rows'   => $rows,
                'capped' => count($rows) >= self::SCAN_TIME_SAMPLE_CAP,
                'error'  => null,
            ];
        } catch (\Throwable $e) {
            return ['rows' => [], 'capped' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Compute time-to-scan in hours for each row, dropping rows where
     * either timestamp is missing or the diff is negative (data error).
     *
     * @return float[] sorted ascending
     */
    private function timeDeltasHours(array $scanTimes): array
    {
        $deltas = [];
        foreach ($scanTimes['rows'] ?? [] as $r) {
            $sd = $r['scanned_date'] ?? null;
            $cd = $r['create_date']  ?? null;
            if (!is_string($sd) || !is_string($cd) || $sd === '' || $cd === '') continue;
            try {
                $scan = new \DateTimeImmutable($sd);
                $crea = new \DateTimeImmutable($cd);
                $diffSec = $scan->getTimestamp() - $crea->getTimestamp();
                if ($diffSec < 0) continue;  // sanity — scanned before created shouldn't happen
                $deltas[] = $diffSec / 3600.0;
            } catch (\Throwable) {
                continue;
            }
        }
        sort($deltas);
        return $deltas;
    }

    private function medianTimeToScan(array $cyScanTimes, array $pyScanTimes): array
    {
        try {
            $cyDeltas = $this->timeDeltasHours($cyScanTimes);
            $pyDeltas = $this->timeDeltasHours($pyScanTimes);

            if (empty($cyDeltas)) {
                return ['value' => '— —', 'sub' => 'No scans in period', 'pending' => false];
            }

            // Median = middle element (or average of two middles for even N).
            $cyMedian = $this->percentile($cyDeltas, 50.0);
            $pyMedian = !empty($pyDeltas) ? $this->percentile($pyDeltas, 50.0) : null;
            $cyP90    = $this->percentile($cyDeltas, 90.0);

            // Lower = better, so vsPy needs to be inverted in the view.
            // We expose the raw delta and let the view colour it.
            $vsPy = $pyMedian !== null && $pyMedian > 0
                ? -100.0 * ($cyMedian - $pyMedian) / $pyMedian   // negate so faster = positive
                : null;

            return [
                'value'   => $this->formatHours($cyMedian),
                'sub'     => 'P90: ' . $this->formatHours($cyP90)
                          . ($cyScanTimes['capped'] ?? false ? ' · top 10K sample' : ''),
                'pending' => false,
                'vsPy'    => $vsPy,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Median time-to-scan', $e);
        }
    }

    private function sameDayScanRate(array $cyScanTimes, array $pyScanTimes): array
    {
        try {
            $cyDeltas = $this->timeDeltasHours($cyScanTimes);
            $pyDeltas = $this->timeDeltasHours($pyScanTimes);

            if (empty($cyDeltas)) {
                return ['value' => '— —', 'sub' => 'No scans in period', 'pending' => false];
            }

            $cySameDay = count(array_filter($cyDeltas, fn ($h) => $h <= 24.0));
            $cyPct     = 100.0 * $cySameDay / count($cyDeltas);

            $pyPct = null;
            if (!empty($pyDeltas)) {
                $pySameDay = count(array_filter($pyDeltas, fn ($h) => $h <= 24.0));
                $pyPct     = 100.0 * $pySameDay / count($pyDeltas);
            }

            return [
                'value'   => number_format($cyPct, 1) . '%',
                'sub'     => number_format($cySameDay) . ' of ' . number_format(count($cyDeltas))
                          . ' scanned within 24h',
                'pending' => false,
                'vsPy'    => $pyPct !== null ? $cyPct - $pyPct : null,  // pp delta
            ];
        } catch (\Throwable $e) {
            return $this->failed('Same-day scan rate', $e);
        }
    }

    /**
     * Compute the per-category container counts and HT revenue for scans
     * in the period, applying the official price grid.
     *
     * @return array{categories: array<string,array{label:string,count:int,price:int,revenue:int}>, total_count:int, total_revenue:int, free_count:int, error:?string}
     */
    private function scanRevenueBreakdown(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $emptyResult = [
            'categories'    => [],
            'total_count'   => 0,
            'total_revenue' => 0,
            'free_count'    => 0,
            'error'         => null,
        ];

        try {
            // Period anchor for revenue is `scanned_date` — the moment of
            // scan = the moment the fee is earned.
            $base = [
                ['scanned', '=', true],
                ['scanned_date', '>=', $from->format('Y-m-d 00:00:00')],
                ['scanned_date', '<=', $to->format('Y-m-d 23:59:59')],
            ];

            // Helper closures so the four billable categories don't bury
            // the intent in domain noise.
            $count = fn (array $extra) => $this->odoo->searchCount(
                self::MODEL_EQUIPMENT, array_merge($base, $extra)
            );

            // Billable categories — full containers, NOT in transit.
            $impFull20 = $count([
                ['full', '=', 'yes'],
                ['size', '=', '20'],
                ['cargo_id.direction', '=', 'in'],
                ['cargo_id.journey_type', '!=', 'transit'],
            ]);
            $impFull40 = $count([
                ['full', '=', 'yes'],
                ['size', 'in', ['40', '45']],
                ['cargo_id.direction', '=', 'in'],
                ['cargo_id.journey_type', '!=', 'transit'],
            ]);
            $expFull20 = $count([
                ['full', '=', 'yes'],
                ['size', '=', '20'],
                ['cargo_id.direction', '=', 'out'],
                ['cargo_id.journey_type', '!=', 'transit'],
            ]);
            $expFull40 = $count([
                ['full', '=', 'yes'],
                ['size', 'in', ['40', '45']],
                ['cargo_id.direction', '=', 'out'],
                ['cargo_id.journey_type', '!=', 'transit'],
            ]);

            // Free categories — counted but charged at 0.
            $emptyAny  = $count([['full', '=', 'no']]);
            $transitAny = $count([['cargo_id.journey_type', '=', 'transit']]);

            // ── "Autres" (non-container) sources from the price grid ──
            //
            // Vehicles — classified by `cylinders` field, which mirrors the
            // `size` codes in aw_cargo_invoice.product (the invoice billing
            // legend). See the comment on PRICE_HT_WOOD_PER_TRUCK for the
            // mapping and why the residual bucket is non-billable.
            //
            // Vehicle scan filter — the same shape we use for containers
            // and bois: `scanned=true` with `scanned_date` in the period.
            //
            // History note: PR #33 swapped this for a state-in-[invoiced,
            // paid,...] filter on the assumption that the `scanned` flag
            // wasn't being maintained on this customer's workflow. That
            // was based on a misleading sample (the probe's "latest 1000
            // by id desc" census skews to fresh imports still in draft).
            // The Vehicle Scan Analyzer (PR #37) showed the truth: April
            // 2026 alone had 3,167 vehicles with scanned=true — sitting
            // mostly at state=draft because this workflow scans BEFORE
            // billing, so the state-based filter was excluding every
            // scanned-but-not-yet-billed record (the majority).
            //
            // PR #33 also added `['active', '=', true]`, but
            // aw_vehicule.vehicule has NO `active` field — Odoo throws
            // "Invalid field" on every search, the try/catch in vehCount
            // swallowed it, and the dashboard rows silently rendered 0.
            // That's why /operations has been showing 0 / 0 / 0 since
            // PR #33 shipped.
            //
            // Reverting to the original literal-scan filter. The
            // categorisation by cylinders below is unchanged.
            $vehBase = [
                ['scanned', '=', true],
                ['scanned_date', '>=', $from->format('Y-m-d 00:00:00')],
                ['scanned_date', '<=', $to->format('Y-m-d 23:59:59')],
            ];
            $vehCount = function (array $extra) use ($vehBase) {
                try {
                    return $this->odoo->searchCount(
                        'aw_vehicule.vehicule',
                        array_merge($vehBase, $extra)
                    );
                } catch (\Throwable $e) {
                    // model unreachable / unknown — leave at 0
                    return 0;
                }
            };

            $voitureScanned   = $vehCount([['cylinders', 'in', [4, 6]]]);
            $camionScanned    = $vehCount([['cylinders', '=', 8]]);
            $bulldozeScanned  = $vehCount([['cylinders', '=', 12]]);
            $vehResidual      = $vehCount([['cylinders', 'not in', [4, 6, 8, 12]]]);

            // Bois: tariff is per camion/wagon, not per piece of wood.
            // Best proxy: count distinct cargo_id values that contain at
            // least one scanned bois record in the period (each cargo ~
            // one truckload). The im_vehicule field on aw_bois.bois would
            // be more granular but is mostly empty in the live data.
            $woodTruckloads = 0;
            try {
                $boisGroups = $this->odoo->callModel('aw_bois.bois', 'read_group', [
                    'domain'  => [
                        ['scanned', '=', true],
                        ['scanned_date', '>=', $from->format('Y-m-d 00:00:00')],
                        ['scanned_date', '<=', $to->format('Y-m-d 23:59:59')],
                    ],
                    'fields'  => [],
                    'groupby' => ['cargo_id'],
                    'lazy'    => false,
                ]);
                $woodTruckloads = count(array_filter(
                    $boisGroups,
                    fn ($r) => !empty($r['cargo_id']) && $r['cargo_id'] !== false
                ));
            } catch (\Throwable $e) {
                // model unreachable / unknown — leave at 0
            }

            $categories = [
                'import_full_40' => [
                    'label'   => "Import Full 40' / 45'",
                    'count'   => $impFull40,
                    'price'   => self::PRICE_HT_IMPORT_FULL_40,
                    'revenue' => $impFull40 * self::PRICE_HT_IMPORT_FULL_40,
                ],
                'import_full_20' => [
                    'label'   => "Import Full 20'",
                    'count'   => $impFull20,
                    'price'   => self::PRICE_HT_IMPORT_FULL_20,
                    'revenue' => $impFull20 * self::PRICE_HT_IMPORT_FULL_20,
                ],
                'export_full_40' => [
                    'label'   => "Export Full 40' / 45'",
                    'count'   => $expFull40,
                    'price'   => self::PRICE_HT_EXPORT_FULL_40,
                    'revenue' => $expFull40 * self::PRICE_HT_EXPORT_FULL_40,
                ],
                'export_full_20' => [
                    'label'   => "Export Full 20'",
                    'count'   => $expFull20,
                    'price'   => self::PRICE_HT_EXPORT_FULL_20,
                    'revenue' => $expFull20 * self::PRICE_HT_EXPORT_FULL_20,
                ],
                // Vehicles — split into the four billing categories from
                // aw_cargo_invoice.product, keyed off cylinders. Rates from
                // the Grille Tarifaire (editable in the simulation block).
                'voiture' => [
                    'label'   => 'Voiture (4 / 6 cyl)',
                    'count'   => $voitureScanned,
                    'price'   => self::PRICE_HT_VOITURE,
                    'revenue' => $voitureScanned * self::PRICE_HT_VOITURE,
                ],
                'camion' => [
                    'label'   => 'Camion / Truck (8 cyl)',
                    'count'   => $camionScanned,
                    'price'   => self::PRICE_HT_CAMION,
                    'revenue' => $camionScanned * self::PRICE_HT_CAMION,
                ],
                'bulldoze' => [
                    'label'   => 'Bulldoze / heavy (12 cyl)',
                    'count'   => $bulldozeScanned,
                    'price'   => self::PRICE_HT_BULLDOZE,
                    'revenue' => $bulldozeScanned * self::PRICE_HT_BULLDOZE,
                ],
                'vehicles_unclassified' => [
                    // Residual = scanned vehicles whose cylinders field
                    // wasn't filled in (cylinders ∉ {4, 6, 8, 12} — almost
                    // all are cylinders=0, with a small tail at 9). The
                    // analyzer (PR #37/39) confirmed these are real cars
                    // — sample names show TOYOTA Corolla / RAV / Avensis /
                    // Yaris — they just haven't been categorised in Odoo.
                    //
                    // We default to the Voiture rate (the cheapest of the
                    // four) so the projection includes them WITHOUT
                    // over-claiming. The `caveat: true` flag keeps the
                    // table footnote ("assumed Voiture") visible so the
                    // operator knows we made an assumption — and so the
                    // customer is nudged to fix the categorisation in
                    // Odoo for an exact figure.
                    'label'   => 'Other / unclassified (assumed Voiture) *',
                    'count'   => $vehResidual,
                    'price'   => self::PRICE_HT_VOITURE,
                    'revenue' => $vehResidual * self::PRICE_HT_VOITURE,
                    'caveat'  => true,
                    'assumed' => true,
                ],
                'wood_per_truckload' => [
                    'label'   => 'Bois (per truck/wagon) **',
                    'count'   => $woodTruckloads,
                    'price'   => self::PRICE_HT_WOOD_PER_TRUCK,
                    'revenue' => $woodTruckloads * self::PRICE_HT_WOOD_PER_TRUCK,
                    'caveat'  => true,
                ],
                'transit' => [
                    'label'   => 'Transit (any size)',
                    'count'   => $transitAny,
                    'price'   => 0,
                    'revenue' => 0,
                ],
                'empty' => [
                    'label'   => 'Empty / Vide (any size)',
                    'count'   => $emptyAny,
                    'price'   => 0,
                    'revenue' => 0,
                ],
            ];

            // Skip null-priced categories (e.g., unclassified vehicles) when
            // summing revenue — we explicitly don't bill them, so they
            // mustn't inflate the total.
            $totalRevenue = (int) array_sum(array_map(
                fn ($r) => is_numeric($r['revenue']) ? $r['revenue'] : 0,
                $categories
            ));
            $totalCount   = array_sum(array_column($categories, 'count'));
            $freeCount    = $emptyAny + $transitAny;

            return [
                'categories'    => $categories,
                'total_count'   => $totalCount,
                'total_revenue' => $totalRevenue,
                'free_count'    => $freeCount,
                'error'         => null,
            ];
        } catch (\Throwable $e) {
            return array_merge($emptyResult, ['error' => $e->getMessage()]);
        }
    }

    private function scanRevenue(array $cy, array $py): array
    {
        if (!empty($cy['error'])) {
            return ['value' => null, 'sub' => 'Scan revenue', 'pending' => true, 'error' => $cy['error']];
        }
        $cyRevenue = (int) ($cy['total_revenue'] ?? 0);
        $pyRevenue = (int) ($py['total_revenue'] ?? 0);

        $billable = ($cy['total_count'] ?? 0) - ($cy['free_count'] ?? 0);
        $free     = $cy['free_count'] ?? 0;

        return [
            'value'   => ea_money_short((float) $cyRevenue, 'XAF'),
            'sub'     => number_format($billable) . ' billable · ' . number_format($free) . ' free',
            'pending' => false,
            'vsPy'    => $pyRevenue > 0 ? 100.0 * ($cyRevenue - $pyRevenue) / $pyRevenue : null,
        ];
    }

    private function mixBySize(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo): array
    {
        try {
            $rows = $this->odoo->callModel(self::MODEL_EQUIPMENT, 'read_group', [
                'domain'  => $this->stdDomain($cyFrom, $cyTo),
                'fields'  => [],
                'groupby' => ['size'],
                'lazy'    => false,
            ]);

            $total = 0;
            $bySize = [];
            foreach ($rows as $r) {
                $key = (string) ($r['size'] ?? 'unknown');
                if ($key === '' || $key === 'false') $key = 'n/a';
                $cnt = (int) ($r['__count'] ?? $r['size_count'] ?? 0);
                $bySize[$key] = ($bySize[$key] ?? 0) + $cnt;
                $total += $cnt;
            }
            arsort($bySize);

            $parts = [];
            foreach (array_slice($bySize, 0, 4, true) as $size => $cnt) {
                $pct = $total > 0 ? round(100.0 * $cnt / $total) : 0;
                $label = match ($size) {
                    '20'    => "20'",
                    '40'    => "40'",
                    '45'    => "45'",
                    'n/a'   => 'n/a',
                    default => $size,
                };
                $parts[] = "{$label} {$pct}%";
            }

            return [
                'value'   => number_format($total),
                'sub'     => implode(' · ', $parts) ?: 'no records',
                'pending' => false,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Mix by size', $e);
        }
    }

    private function fullEmptyMix(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo): array
    {
        try {
            $rows = $this->odoo->callModel(self::MODEL_EQUIPMENT, 'read_group', [
                'domain'  => $this->stdDomain($cyFrom, $cyTo),
                'fields'  => [],
                'groupby' => ['full'],
                'lazy'    => false,
            ]);

            $full  = 0;
            $empty = 0;
            $other = 0;
            foreach ($rows as $r) {
                $key = (string) ($r['full'] ?? '');
                $cnt = (int) ($r['__count'] ?? $r['full_count'] ?? 0);
                if ($key === 'yes')      $full  += $cnt;
                elseif ($key === 'no')   $empty += $cnt;
                else                     $other += $cnt;
            }
            $total = $full + $empty + $other;
            $fullPct = $total > 0 ? 100.0 * $full / $total : 0.0;

            return [
                'value'   => number_format($fullPct, 1) . '% full',
                'sub'     => number_format($full) . ' laden · ' . number_format($empty) . ' empty',
                'pending' => false,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Laden share', $e);
        }
    }

    private function directionMix(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo): array
    {
        try {
            // Direction lives on cargo, not equipment. Aggregate at cargo
            // level and weight by equipment_count so the breakdown reflects
            // container volume rather than BL count.
            $rows = $this->odoo->callModel(self::MODEL_CARGO, 'read_group', [
                'domain'  => $this->stdDomain($cyFrom, $cyTo),
                'fields'  => ['equipment_count:sum'],
                'groupby' => ['direction'],
                'lazy'    => false,
            ]);

            $imp = 0;
            $exp = 0;
            $other = 0;
            foreach ($rows as $r) {
                $key = (string) ($r['direction'] ?? '');
                $cnt = (int) ($r['equipment_count'] ?? 0);
                if ($key === 'in')        $imp += $cnt;
                elseif ($key === 'out')   $exp += $cnt;
                else                      $other += $cnt;
            }
            $total = $imp + $exp + $other;
            $impPct = $total > 0 ? round(100.0 * $imp / $total) : 0;
            $expPct = $total > 0 ? round(100.0 * $exp / $total) : 0;

            return [
                'value'   => "{$impPct}% Imp · {$expPct}% Exp",
                'sub'     => number_format($imp) . ' in · ' . number_format($exp) . ' out',
                'pending' => false,
            ];
        } catch (\Throwable $e) {
            return $this->failed('Direction mix', $e);
        }
    }

    /* ── Shared helpers ───────────────────────────────────────────────── */

    /**
     * Standard period + active filter applied to all logistique models.
     * Period anchor: create_date (always populated by Odoo).
     */
    private function stdDomain(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            ['create_date', '>=', $from->format('Y-m-d 00:00:00')],
            ['create_date', '<=', $to->format('Y-m-d 23:59:59')],
            ['active', '=', true],
        ];
    }

    private function failed(string $sub, \Throwable $e): array
    {
        return [
            'value'   => null,
            'sub'     => $sub,
            'pending' => true,
            'error'   => $e->getMessage(),
        ];
    }

    /**
     * Mon-Sat working days in [from, to]. Matches RevenueService basis so
     * any "per day" KPI is consistent with the Dashboard's RpD/Run-Rate.
     */
    private function workingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to < $from) return 0;
        $days = 0;
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            // format('w'): Sunday = 0 → skip; everything else (Mon-Sat) counts.
            if ((int) $d->format('w') === 0) continue;
            $days++;
        }
        return max(1, $days);
    }

    private function detectCurrency(): string
    {
        try {
            $user = $this->odoo->read('res.users', [$this->odoo->authenticate()], ['company_id']);
            $companyId = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $company = $this->odoo->read('res.company', [$companyId], ['currency_id']);
            $curId = isset($company[0]['currency_id'][0]) ? (int) $company[0]['currency_id'][0] : 0;
            if ($curId > 0) {
                $cur = $this->odoo->read('res.currency', [$curId], ['name']);
                if (isset($cur[0]['name'])) return (string) $cur[0]['name'];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return (string) (config_env('CURRENCY', 'XAF') ?: 'XAF');
    }

    private function pct(?float $a, ?float $b): ?float
    {
        if (!is_numeric($a) || !is_numeric($b)) return null;
        if ((float) $b === 0.0) return ((float) $a === 0.0) ? 0.0 : null;
        return (((float) $a - (float) $b) / abs((float) $b)) * 100.0;
    }

    /**
     * Percentile of a SORTED ascending array. Linear interpolation
     * between the two closest ranks (PostgreSQL's percentile_cont
     * convention).
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float) $sorted[0];
        $rank = ($p / 100.0) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) return (float) $sorted[$lo];
        $frac = $rank - $lo;
        return (float) $sorted[$lo] + $frac * ((float) $sorted[$hi] - (float) $sorted[$lo]);
    }

    /**
     * Format an hour count compactly:
     *   < 1h   → "47 min"
     *   < 24h  → "8.3h"
     *   ≥ 24h  → "2.4d"
     */
    private function formatHours(float $hours): string
    {
        if ($hours < 1.0)   return number_format($hours * 60, 0) . ' min';
        if ($hours < 24.0)  return number_format($hours, 1) . 'h';
        return number_format($hours / 24.0, 1) . 'd';
    }
}
