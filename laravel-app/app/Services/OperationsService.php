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
    private const PRICE_HT_EXPORT_FULL_20 = 38_500;
    private const PRICE_HT_EXPORT_FULL_40 = 50_000;
    private const PRICE_HT_IMPORT_FULL_20 = 66_000;
    private const PRICE_HT_IMPORT_FULL_40 = 95_000;

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
            'pending_overall'   => collect($kpis)->every(fn ($k) => !empty($k['pending'])),
        ];
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

            $totalRevenue = array_sum(array_column($categories, 'revenue'));
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
