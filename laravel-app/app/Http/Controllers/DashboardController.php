<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BillingService;
use App\Services\BudgetService;
use App\Services\PLForecastService;
use App\Services\ProfitLossService;
use App\Services\WorkingCapitalService;
use App\Services\RevenueService;
use App\Services\ReceivablesService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Accept ?period=ytd|mtd|month via the period-filter component.
        // Default YTD (cumulative read most users want first). Month gives
        // power-users access to historical full-month figures (e.g. April
        // 2026) without leaving the dashboard.
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) {
            $type = 'ytd';
        }

        // Year/month optional — only ytd/month consume year, only month
        // consumes month. ea_resolve_period clamps to safe ranges.
        $year  = (int) $request->query('year',  $today->format('Y'));
        $month = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [, , $periodLabel] = ea_resolve_period($today, $period);

        // Period-scoped cache keys so different selections don't clobber
        // each other within a session.
        $cacheTag = "{$type}_{$year}_{$month}";

        $source       = (string) config_env('REVENUE_SOURCE', 'invoice');
        $data         = null;
        $pl           = null;
        $receivables  = null;
        $reconciliation = null;
        $extraKpis    = [];
        $kpiBudgets   = [];
        $error        = null;

        try {
            $client = OdooFactory::fromConfig();
            // Cache key was bumped to `revenue_ht_v1_` on 2026-05-11 when
            // RevenueService switched from amount_total_signed (TTC) to
            // amount_untaxed_signed (HT). Old cached entries are stale and
            // would otherwise serve TTC figures for up to 5 minutes after
            // deploy. The new prefix bypasses them cleanly.
            $data   = ea_cached("revenue_ht_v1_{$cacheTag}",
                fn () => (new RevenueService($client, $source))->analyze(null, $period));
            try {
                $pl = ea_cached("pl_{$cacheTag}",
                    fn () => (new ProfitLossService($client))->analyze(null, $period));
            } catch (\Throwable $e) {
                $pl = null;
            }

            if ($data) {
                try {
                    // Receivables aging is denominator-sensitive: an MTD
                    // window would distort DSO. Always feed it YTD-equivalent
                    // numbers so the AR card reads consistently regardless
                    // of the dashboard timeframe selector.
                    $totalRevenue = (float) ($data['current']['total'] ?? 0);
                    $periodDays   = max(1, (int) ($data['period']['days'] ?? 1));
                    $currency     = (string) ($data['currency'] ?? 'USD');
                    $receivables  = ea_cached("ar_{$cacheTag}", function () use ($client, $totalRevenue, $periodDays, $currency) {
                        return (new ReceivablesService($client))->analyze($totalRevenue, $periodDays, $currency);
                    });
                } catch (\Throwable $e) {
                    $receivables = null;
                }
            }

            // Revenue Reconciliation (Expected → Billed → Cash). Pulls from
            // BillingService so the headline figures match /billing exactly.
            // Cached separately so the dashboard reload is fast even if
            // /billing hasn't been hit yet this session.
            //
            // Gated by the per-tenant `revenue_reconciliation` module flag.
            // When the super-admin disables it from /admin/tenants/{id},
            // we skip the Odoo round-trip entirely and the dashboard view
            // hides the sankey card.
            if (tenant()?->moduleEnabled('revenue_reconciliation')) {
                try {
                    $reconciliation = ea_cached("recon_{$cacheTag}",
                        fn () => (new BillingService($client))->reconciliationOnly(null, $period));
                } catch (\Throwable $e) {
                    $reconciliation = null;
                }
            }

            // ── Month-over-month efficiency KPIs (DSO, DPO, Cost/scan,
            // Revenue/scan, EBITDA margin, EBIT margin). Each carries its
            // latest value + the prior month so the view can draw the
            // better/worse arrow. These are monthly figures that don't move
            // intra-day, so cache for 30 min (vs the 5-min default) to keep
            // the dashboard fast — the underlying Odoo work is non-trivial.
            try {
                $extraKpis = \Illuminate\Support\Facades\Cache::remember(
                    ea_cache_prefix() . "dash_kpis_v3_{$year}", 1800,
                    fn () => $this->buildExtraKpis($client, $year)
                );
            } catch (\Throwable $e) {
                $extraKpis = [];
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // ── Uploaded annual budget (TenantBudget) for the period-aware
        // cumulative chart. The synthesised budget series in $data
        // (RevenueService::buildBudgetPackage) is monthly-flat from
        // env config; the user-uploaded budget gives true per-month
        // figures. We pass the monthly revenue vector (1..12) so the
        // view can render YTD-cumulative or MTD-weekly depending on
        // the active period filter.
        $uploadedBudgetMonthly = null;
        try {
            $tenantInScope = app(TenantContext::class);
            if ($tenantInScope->has()) {
                $summary = (new BudgetService())->summary($tenantInScope->tenant()->id, $year);
                $rev = $summary['data']['revenue'] ?? null;
                if (is_array($rev)) {
                    // Coerce to a flat array indexed 1..12 with floats.
                    $vec = array_fill(1, 12, 0.0);
                    foreach ($rev as $k => $v) {
                        $m = (int) $k;
                        if ($m >= 1 && $m <= 12) $vec[$m] = (float) $v;
                    }
                    $uploadedBudgetMonthly = $vec;
                }
            }
        } catch (\Throwable $e) {
            // Best-effort: missing budget table or upload row → fall through
            // and the dashboard renders without the budget overlay.
        }

        // ── KPI budget targets (flat annual lines on the trend charts).
        // A cheap uncached DB read, kept OUT of the 30-min KPI cache so a
        // freshly-saved target shows on the very next load without a recompute.
        try {
            $ctx = app(TenantContext::class);
            if ($ctx->has()) {
                $kpiBudgets = (new BudgetService())->loadKpiBudgets($ctx->tenant()->id, $year);
            }
        } catch (\Throwable $e) {
            $kpiBudgets = [];
        }

        return view('dashboard', [
            'data'           => $data,
            'pl'             => $pl,
            'receivables'    => $receivables,
            'uploadedBudgetMonthly' => $uploadedBudgetMonthly,
            'reconciliation' => $reconciliation,
            'extraKpis'      => $extraKpis,
            'kpiBudgets'     => $kpiBudgets,
            'error'          => $error,
            'active'         => 'dashboard',
            'period'         => $period,
            'periodLabel'    => $periodLabel,
        ]);
    }

    /**
     * Build the six efficiency KPIs for the dashboard, each with its full
     * 12-month actual series (for the trend charts) plus the latest value
     * and prior month (for the strip's better/worse arrow).
     *
     * Order: DSO, DPO, Cost/scan, Revenue/scan, EBITDA margin, EBIT margin.
     * Each entry: ['label','value','prev','fmt'('days'|'pct'|'money'),'ccy',
     * 'higher_better','series'(0-indexed Jan..Dec, null where no actual)].
     */
    private function buildExtraKpis($client, int $year): array
    {
        $ccy  = 'XAF';
        $thru = 0;
        $rev  = $eb = $ei = [];

        // Monthly P&L — actuals on closed months, forecast after. One call.
        try {
            $pl   = (new PLForecastService($client))->analyze($year);
            $ccy  = (string) ($pl['currency'] ?? 'XAF');
            $thru = (int) ($pl['thru_month'] ?? 0);
            $by   = [];
            foreach (($pl['lines'] ?? []) as $line) { $by[$line['key'] ?? ''] = $line['monthly'] ?? []; }
            $rev = $by['revenue'] ?? []; $eb = $by['ebitda'] ?? []; $ei = $by['ebit'] ?? [];
        } catch (\Throwable $e) { /* series stay empty */ }

        // Monthly scan counts for the whole year (3 read_group calls total).
        $scans = $this->scansByMonth($client, $year);

        // Monthly DSO/DPO from the liquidity trend, mapped onto month numbers.
        $dsoM = array_fill(1, 12, null);
        $dpoM = array_fill(1, 12, null);
        try {
            $series = (new WorkingCapitalService($client))->liquidityTrend(12)['series'] ?? [];
            foreach ($series as $p) {
                $d = (string) ($p['date'] ?? '');
                if (strlen($d) >= 7 && (int) substr($d, 0, 4) === $year) {
                    $mm = (int) substr($d, 5, 2);
                    if ($mm >= 1 && $mm <= 12) {
                        if (($p['dso'] ?? null) !== null) $dsoM[$mm] = (float) $p['dso'];
                        if (($p['dpo'] ?? null) !== null) $dpoM[$mm] = (float) $p['dpo'];
                    }
                }
            }
        } catch (\Throwable $e) { /* DSO/DPO stay null */ }

        // Per-scan + margin monthly series. Only closed months (<= thru)
        // carry an actual; forecast months stay null so the charts never
        // present projections as actuals.
        $maxM    = max(0, min(12, $thru));
        $revScan = array_fill(1, 12, null);
        $costScan = array_fill(1, 12, null);
        $ebitdaM = array_fill(1, 12, null);
        $ebitM   = array_fill(1, 12, null);
        for ($m = 1; $m <= 12; $m++) {
            $r = (float) ($rev[$m] ?? 0);
            if ($m <= $maxM && $r != 0.0) {
                $ebitdaM[$m] = (float) ($eb[$m] ?? 0) / $r * 100.0;
                $ebitM[$m]   = (float) ($ei[$m] ?? 0) / $r * 100.0;
            }
            $s = (int) ($scans[$m] ?? 0);
            if ($m <= $maxM && $s > 0) {
                $revScan[$m]  = $r / $s;
                $costScan[$m] = ($r - (float) ($ei[$m] ?? 0)) / $s;   // cost ≈ revenue − EBIT
            }
        }

        // Latest value + prior non-null point, for the strip's arrow.
        $lastTwo = function (array $byMonth): array {
            $vals = [];
            foreach ($byMonth as $v) { if ($v !== null) $vals[] = (float) $v; }
            $n = count($vals);
            return [$n >= 1 ? $vals[$n - 1] : null, $n >= 2 ? $vals[$n - 2] : null];
        };

        $defs = [
            'dso'           => ['DSO',            'days',  false, $dsoM],
            'dpo'           => ['DPO',            'days',  true,  $dpoM],
            'cost_per_scan' => ['Cost / scan',    'money', false, $costScan],
            'rev_per_scan'  => ['Revenue / scan', 'money', true,  $revScan],
            'ebitda_margin' => ['EBITDA margin',  'pct',   true,  $ebitdaM],
            'ebit_margin'   => ['EBIT margin',    'pct',   true,  $ebitM],
        ];

        $out = [];
        foreach ($defs as $key => [$label, $fmt, $hb, $byMonth]) {
            [$val, $prev] = $lastTwo($byMonth);
            $out[$key] = [
                'label'         => $label,
                'value'         => $val,
                'prev'          => $prev,
                'fmt'           => $fmt,
                'ccy'           => $ccy,
                'higher_better' => $hb,
                'series'        => array_values($byMonth),   // 0-indexed Jan..Dec
            ];
        }
        return $out;
    }

    /**
     * Scan counts per calendar month for a year — containers + vehicles +
     * distinct wood truckloads (cargo_id), filtered scanned=true. Three
     * read_group-by-month calls cover the whole year (vs one call per month),
     * keeping the dashboard's cold build cheap. Returns [1..12 => int].
     *
     * Odoo keys the month window under __range[FIELD] ('scanned_date'), NOT
     * __range['scanned_date:month']; the French month labels in the value
     * field can't be reparsed, so we read the range's `from`.
     */
    private function scansByMonth($client, int $year): array
    {
        $out = array_fill(1, 12, 0);
        $win = [
            ['scanned', '=', true],
            ['scanned_date', '>=', sprintf('%04d-01-01 00:00:00', $year)],
            ['scanned_date', '<=', sprintf('%04d-12-31 23:59:59', $year)],
        ];
        $monthOf = function (array $row): ?int {
            foreach (['scanned_date', 'scanned_date:month'] as $rk) {
                if (isset($row['__range'][$rk]['from'])) {
                    $f = (string) $row['__range'][$rk]['from'];
                    if (strlen($f) >= 7) return (int) substr($f, 5, 2);
                }
            }
            return null;
        };

        // Containers + vehicles: one group per month, __count = scans.
        foreach (['aw_cargo.equipment', 'aw_vehicule.vehicule'] as $model) {
            try {
                $g = $client->callModel($model, 'read_group', [
                    'domain' => $win, 'fields' => [], 'groupby' => ['scanned_date:month'], 'lazy' => false,
                ]);
                foreach ((array) $g as $row) {
                    $m = $monthOf($row);
                    if ($m !== null && isset($out[$m])) $out[$m] += (int) ($row['__count'] ?? 0);
                }
            } catch (\Throwable $e) { /* model absent → skip */ }
        }

        // Wood: distinct cargo_id per month ≈ one truckload. With two groupby
        // keys + lazy=false Odoo emits one row per (month, cargo), so each
        // non-empty cargo_id row counts as one truckload that month.
        try {
            $g = $client->callModel('aw_bois.bois', 'read_group', [
                'domain' => $win, 'fields' => [], 'groupby' => ['scanned_date:month', 'cargo_id'], 'lazy' => false,
            ]);
            foreach ((array) $g as $row) {
                if (empty($row['cargo_id']) || $row['cargo_id'] === false) continue;
                $m = $monthOf($row);
                if ($m !== null && isset($out[$m])) $out[$m] += 1;
            }
        } catch (\Throwable $e) { /* model absent → skip */ }

        return $out;
    }

    public function sync(Request $request)
    {
        // The dashboard "KPI Targets" form reuses this already-cached POST
        // route (a dedicated /dashboard/kpi-targets route would 404 behind the
        // stale route cache on this host). Delegate on the intent marker
        // before the default cache-refresh behaviour.
        if ($request->input('_intent') === 'kpi_budget') {
            return $this->saveKpiBudget($request);
        }

        ea_cache_clear();
        return back()->with('flash', ['type' => 'success', 'msg' => 'Data refreshed — next load will hit Odoo live.']);
    }

    /**
     * Persist the dashboard KPI budget targets (rev/scan, cost/scan, EBITDA%,
     * EBIT%, DSO, DPO) for the active tenant + year. Stored outside the KPI
     * cache, so the new budget line shows on the next dashboard load.
     */
    private function saveKpiBudget(Request $request)
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->has()) abort(404);
        $tenant = $ctx->tenant();

        $v = $request->validate([
            'year'     => ['required', 'integer'],
            'budget'   => ['nullable', 'array'],
            'budget.*' => ['nullable', 'numeric'],
        ]);
        $year = max(2018, min((int) (new \DateTimeImmutable())->format('Y') + 2, (int) $v['year']));

        app(BudgetService::class)->saveKpiBudgets(
            $tenant, $year, (array) ($v['budget'] ?? []), optional($request->user())->id
        );

        return redirect()->to(tenant_url('/'))->with('flash', [
            'type' => 'success',
            'msg'  => 'KPI targets saved.',
        ]);
    }
}
