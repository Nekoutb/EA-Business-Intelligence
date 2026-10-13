<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Profitability — unit economics + profit-growth analytics for a logistics
 * (scan-based) operation.
 *
 * Brings three things onto one page:
 *
 *   1. Unit economics (per scan), over the actual period (YTD for the current
 *      year, full year for past years), so numerator and denominator cover the
 *      same window:
 *        - Revenue per scan      = Total revenue            ÷ total scans
 *        - Cost per scan         = Total operating cost     ÷ total scans
 *                                  (COGS + OpEx + D&A = Revenue − EBIT)
 *        - Employee cost per scan = SYSCOHADA class-66 cost ÷ total scans
 *
 *   2. Profit growth — Revenue, EBITDA, EBIT vs the prior-year same window
 *      (delegated to ProfitLossService, which already computes YoY deltas),
 *      plus a monthly Revenue/EBITDA/EBIT trend for the selected year.
 *
 *   3. Revenue per day — monthly revenue ÷ calendar days in the month.
 *
 * "Total scans" is a true COUNT, summed across the customer's three AW Cargo
 * scan models (same source as the Operations page):
 *      containers (aw_cargo.equipment) + vehicles (aw_vehicule.vehicule)
 *      + wood truckloads (distinct cargo on aw_bois.bois),
 * all filtered by `scanned = true` and `scanned_date` in the window.
 *
 * Every sub-computation is fail-safe: if a scan model is absent (non-logistics
 * tenant) or a query throws, that piece degrades to 0/null and the rest of the
 * page still renders. `notes` records what could not be computed.
 */
final class ProfitabilityService
{
    /** AW Cargo scan models (same as OperationsService). */
    private const MODEL_CONTAINER = 'aw_cargo.equipment';
    private const MODEL_VEHICLE   = 'aw_vehicule.vehicule';
    private const MODEL_WOOD      = 'aw_bois.bois';

    /** SYSCOHADA "Charges de personnel" — employee/FTE cost. */
    private const PERSONNEL_PREFIX = '66';

    private const MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    private OdooClient $odoo;

    /**
     * @param ?string $fileRefField  set → unit = file (distinct invoice
     *   references on class-70 lines, see FileUnitEconomics) instead of scan.
     */
    public function __construct(OdooClient $odoo, private ?string $fileRefField = null)
    {
        $this->odoo = $odoo;
    }

    /**
     * @param int        $year         Fiscal year to analyse.
     * @param ?array     $assumptions  Forecast assumptions (threaded to the monthly trend).
     * @param ?\DateTimeImmutable $today
     */
    public function analyze(int $year, ?array $assumptions = null, ?\DateTimeImmutable $today = null): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $notes = [];

        // Actual reporting window: YTD for the current year, full year for past.
        [$from, $to, $periodLabel] = ea_resolve_period($today, ['type' => 'ytd', 'year' => $year]);
        $pyFrom = $from->modify('-1 year');
        $pyTo   = $to->modify('-1 year');

        // ── 1. P&L totals + YoY growth (current vs prior-year same window) ──
        $pl = ['current' => [], 'previous' => [], 'delta' => [], 'currency' => 'XAF'];
        try {
            $pl = (new ProfitLossService($this->odoo))->analyze($today, ['type' => 'ytd', 'year' => $year]);
        } catch (\Throwable $e) {
            $notes[] = 'P&L figures unavailable.';
        }
        $cur      = $pl['current']  ?? [];
        $prev     = $pl['previous'] ?? [];
        $delta    = $pl['delta']    ?? [];
        $currency = $pl['currency'] ?? 'XAF';

        $revenue   = (float) ($cur['revenue'] ?? 0);
        $totalCost = (float) ($cur['cogs'] ?? 0) + (float) ($cur['opex'] ?? 0) + (float) ($cur['d_and_a'] ?? 0);
        $ebitda    = (float) ($cur['ebitda'] ?? 0);
        $ebit      = (float) ($cur['ebit'] ?? 0);

        $pyRevenue   = (float) ($prev['revenue'] ?? 0);
        $pyTotalCost = (float) ($prev['cogs'] ?? 0) + (float) ($prev['opex'] ?? 0) + (float) ($prev['d_and_a'] ?? 0);

        // ── 2. Employee / personnel cost (SYSCOHADA class 66) ──
        $employeeCost   = $this->personnelCost($from, $to, $notes);
        $pyEmployeeCost = $this->personnelCost($pyFrom, $pyTo, $notes);

        // ── 3. Total units (scans, or files) for the same window ──
        $fileMonths = null;
        if ($this->fileRefField !== null) {
            $fue = new FileUnitEconomics($this->odoo, $this->fileRefField);
            $fileMonths = $fue->refsByMonth($from->format('Y-m-d'), $to->format('Y-m-d'));
            $fc  = FileUnitEconomics::countsWithOverrides($fileMonths, $from->format('Y-m-d'), $to->format('Y-m-d'));
            $nf  = $fc['total'];
            $fileCountM = $fc['by_month'];
            $pyF = FileUnitEconomics::countsWithOverrides($fue->refsByMonth($pyFrom->format('Y-m-d'), $pyTo->format('Y-m-d')),
                $pyFrom->format('Y-m-d'), $pyTo->format('Y-m-d'))['total'];
            if ($fc['manual']) $notes[] = 'File counts include manual monthly figures from Productivity settings.';
            $scans   = ['total' => $nf,  'total_ex_empty' => $nf,  'available' => true];
            $pyScans = ['total' => $pyF, 'total_ex_empty' => $pyF, 'available' => true];
        } else {
            $scans   = $this->totalScans($from, $to, $notes);
            $pyScans = $this->totalScans($pyFrom, $pyTo, $notes);
        }
        $n   = (int) $scans['total'];
        $pyN = (int) $pyScans['total'];

        // ── 4. Unit economics ──
        $revPerScan  = $n > 0 ? $revenue   / $n : null;
        $costPerScan = $n > 0 ? $totalCost / $n : null;
        $ftePerScan  = $n > 0 ? $employeeCost / $n : null;

        $pyRevPerScan  = $pyN > 0 ? $pyRevenue     / $pyN : null;
        $pyCostPerScan = $pyN > 0 ? $pyTotalCost   / $pyN : null;
        $pyFtePerScan  = $pyN > 0 ? $pyEmployeeCost / $pyN : null;

        // ── 5. Monthly Revenue / EBITDA / EBIT trend (selected year) ──
        $trend = $this->monthlyTrend($year, $assumptions, $notes);

        // ── 6. Revenue per (calendar) day, monthly ──
        $revPerDay = [];
        foreach (range(1, 12) as $m) {
            $days = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)))->format('t');
            $rev  = (float) ($trend['revenue'][$m - 1] ?? 0);
            $revPerDay[] = $days > 0 ? $rev / $days : 0.0;
        }

        // ── 7. Monthly unit economics — CLOSED months only. We use actual
        //       revenue and actual cost ÷ actual scans. Forecast/partial months
        //       are left null: their revenue is forecast while scans are partial
        //       (current month) or absent (future), which would distort per-scan.
        $thru     = (int) ($trend['thru_month'] ?? 0);
        $psRev    = array_fill(0, 12, null);
        $psCost   = array_fill(0, 12, null);
        $psRevEx  = array_fill(0, 12, null);   // excluding empty containers
        $psCostEx = array_fill(0, 12, null);
        $mScans   = array_fill(0, 12, null);
        $mScansEx = array_fill(0, 12, null);
        for ($m = 1; $m <= min(12, $thru); $m++) {
            $mFrom = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $mTo   = $mFrom->modify('last day of this month')->setTime(23, 59, 59);
            $c     = $fileMonths !== null
                ? ['total' => $fileCountM[$mFrom->format('Y-m')] ?? 0, 'total_ex_empty' => $fileCountM[$mFrom->format('Y-m')] ?? 0]
                : $this->scanCounts($mFrom, $mTo);
            $sc    = (int) $c['total'];
            $scEx  = (int) $c['total_ex_empty'];
            $mScans[$m - 1]   = $sc;
            $mScansEx[$m - 1] = $scEx;
            $rev  = (float) ($trend['revenue'][$m - 1] ?? 0);
            $cost = $rev - (float) ($trend['ebit'][$m - 1] ?? 0);   // total cost = revenue − EBIT
            if ($sc > 0)   { $psRev[$m - 1]   = $rev / $sc;   $psCost[$m - 1]   = $cost / $sc; }
            if ($scEx > 0) { $psRevEx[$m - 1] = $rev / $scEx; $psCostEx[$m - 1] = $cost / $scEx; }
        }
        $monthlyPerScan = [
            'labels'           => self::MONTH_LABELS,
            'revenue'          => $psRev,
            'cost'             => $psCost,
            'revenue_ex_empty' => $psRevEx,
            'cost_ex_empty'    => $psCostEx,
            'scans'            => $mScans,
            'scans_ex_empty'   => $mScansEx,
            'thru_month'       => $thru,
        ];

        $pyEbitda = (float) ($prev['ebitda'] ?? 0);
        return [
            'unit'     => $fileMonths !== null ? 'file' : 'scan',
            'currency' => $currency,
            'year'     => $year,
            'period'   => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $periodLabel],
            'totals'   => [
                'revenue'       => $revenue,
                'total_cost'    => $totalCost,
                'employee_cost' => $employeeCost,
                'ebitda'        => $ebitda,
                'ebit'          => $ebit,
            ],
            'scans'    => $scans,
            'per_scan' => [
                'revenue'        => $revPerScan,
                'cost'           => $costPerScan,
                'fte'            => $ftePerScan,
                'ebitda'         => $n > 0 ? $ebitda / $n : null,
                'ebitda_margin'  => $revenue != 0.0 ? $ebitda / $revenue * 100.0 : null,
                'ebitda_vs_py'   => $this->pct($n > 0 ? $ebitda / $n : null, $pyN > 0 ? $pyEbitda / $pyN : null),
                'revenue_vs_py'  => $this->pct($revPerScan,  $pyRevPerScan),
                'cost_vs_py'     => $this->pct($costPerScan, $pyCostPerScan),
                'fte_vs_py'      => $this->pct($ftePerScan,  $pyFtePerScan),
            ],
            'growth'   => [
                'revenue_pct' => $delta['revenue_pct'] ?? null,
                'ebitda_pct'  => $delta['ebitda_pct']  ?? null,
                'ebit_pct'    => $delta['ebit_pct']    ?? null,
            ],
            'trend'           => $trend,
            'rev_per_day'     => ['labels' => self::MONTH_LABELS, 'values' => $revPerDay, 'thru_month' => $trend['thru_month']],
            'monthly_per_scan'=> $monthlyPerScan,
            'notes'           => array_values(array_unique($notes)),
            'fetched_at'  => $today->format('c'),
        ];
    }

    /**
     * Sum SYSCOHADA class-66 (Charges de personnel) move-line balances for a
     * window. Class 66 is a debit-positive expense, so the balance sum is the
     * positive personnel cost. Mirrors ProfitLossService's account/read_group
     * pattern (groupby account_id, sum, total it up).
     */
    private function personnelCost(\DateTimeImmutable $from, \DateTimeImmutable $to, array &$notes): float
    {
        try {
            $accounts = $this->odoo->searchRead('account.account',
                [['code', '=like', self::PERSONNEL_PREFIX . '%']],
                ['id'], []);
            $ids = array_map(static fn ($a) => (int) $a['id'], $accounts);
            if ($ids === []) {
                return 0.0;
            }
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $from->format('Y-m-d')],
                    ['date', '<=', $to->format('Y-m-d')],
                    ['account_id', 'in', $ids],
                ],
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);
            $sum = 0.0;
            foreach ((array) $rows as $r) {
                $sum += (float) ($r['balance'] ?? 0);
            }
            // Net debit = cost. Clamp tiny negatives (reversals) to 0.
            return max(0.0, $sum);
        } catch (\Throwable $e) {
            $notes[] = 'Employee (class 66) cost unavailable.';
            return 0.0;
        }
    }

    /**
     * Total number of scans in a window = containers + vehicles + wood
     * truckloads, each filtered by scanned=true + scanned_date. Each model is
     * queried independently and fails soft to 0 (absent on non-logistics
     * tenants).
     */
    private function totalScans(\DateTimeImmutable $from, \DateTimeImmutable $to, array &$notes): array
    {
        $counts = $this->scanCounts($from, $to);
        if (!$counts['available']) {
            $notes[] = 'Scan counts unavailable (no scan models for this workspace).';
        }
        return $counts;
    }

    /**
     * Container + vehicle + wood-truckload scan counts for a window
     * (scanned=true by scanned_date). No side effects — reused for both the
     * period total and the per-month series. Wood = DISTINCT cargo_id with at
     * least one scanned bois record (one cargo ≈ one truckload).
     */
    private function scanCounts(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $scanWindow = [
            ['scanned', '=', true],
            ['scanned_date', '>=', $from->format('Y-m-d 00:00:00')],
            ['scanned_date', '<=', $to->format('Y-m-d 23:59:59')],
        ];

        $containers = $this->safeCount(self::MODEL_CONTAINER, $scanWindow);
        $vehicles   = $this->safeCount(self::MODEL_VEHICLE, $scanWindow);

        // Empty containers (full='no', any size) are scanned but earn no
        // revenue — counted so callers can exclude them from per-scan ratios.
        $empty = (int) ($this->safeCount(self::MODEL_CONTAINER, array_merge($scanWindow, [['full', '=', 'no']])) ?? 0);

        $wood = 0;
        try {
            $groups = $this->odoo->callModel(self::MODEL_WOOD, 'read_group', [
                'domain'  => $scanWindow,
                'fields'  => [],
                'groupby' => ['cargo_id'],
                'lazy'    => false,
            ]);
            $wood = count(array_filter((array) $groups, static fn ($r) => !empty($r['cargo_id']) && $r['cargo_id'] !== false));
        } catch (\Throwable $e) {
            // model absent — leave wood at 0
        }

        $total = (int) ($containers ?? 0) + (int) ($vehicles ?? 0) + (int) $wood;
        return [
            'containers'      => (int) ($containers ?? 0),
            'vehicles'        => (int) ($vehicles ?? 0),
            'wood'            => (int) $wood,
            'empty_containers'=> $empty,
            'total'           => $total,
            'total_ex_empty'  => max(0, $total - $empty),
            'available'       => ($containers !== null) || ($vehicles !== null),
        ];
    }

    /** searchCount that returns null (not 0) when the model is unreachable. */
    private function safeCount(string $model, array $domain): ?int
    {
        try {
            return $this->odoo->searchCount($model, $domain);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Monthly Revenue / EBITDA / EBIT vectors for the year, from
     * PLForecastService (actuals on closed months, forecast on the rest).
     */
    private function monthlyTrend(int $year, ?array $assumptions, array &$notes): array
    {
        $empty = [
            'labels'     => self::MONTH_LABELS,
            'revenue'    => array_fill(0, 12, 0.0),
            'ebitda'     => array_fill(0, 12, 0.0),
            'ebit'       => array_fill(0, 12, 0.0),
            'thru_month' => 0,
        ];
        try {
            $data  = (new PLForecastService($this->odoo))->analyze($year, null, $assumptions);
            $byKey = [];
            foreach (($data['lines'] ?? []) as $line) {
                $byKey[$line['key'] ?? ''] = $line;
            }
            $vec = function (string $key) use ($byKey): array {
                $monthly = $byKey[$key]['monthly'] ?? [];
                $out = [];
                foreach (range(1, 12) as $m) {
                    $out[] = (float) ($monthly[$m] ?? 0);
                }
                return $out;
            };
            return [
                'labels'     => self::MONTH_LABELS,
                'revenue'    => $vec('revenue'),
                'ebitda'     => $vec('ebitda'),
                'ebit'       => $vec('ebit'),
                'thru_month' => (int) ($data['thru_month'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $notes[] = 'Monthly trend unavailable.';
            return $empty;
        }
    }

    private function pct(?float $cur, ?float $prev): ?float
    {
        if ($cur === null || $prev === null) return null;
        if ($prev == 0.0) return $cur == 0.0 ? 0.0 : null;
        return (($cur - $prev) / abs($prev)) * 100.0;
    }
}
