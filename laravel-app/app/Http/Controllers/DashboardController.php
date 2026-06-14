<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BillingService;
use App\Services\BudgetService;
use App\Services\ProfitLossService;
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
                    ea_cache_prefix() . "dash_kpis_v2_{$year}", 1800,
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

        return view('dashboard', [
            'data'           => $data,
            'pl'             => $pl,
            'receivables'    => $receivables,
            'uploadedBudgetMonthly' => $uploadedBudgetMonthly,
            'reconciliation' => $reconciliation,
            'extraKpis'      => $extraKpis,
            'error'          => $error,
            'active'         => 'dashboard',
            'period'         => $period,
            'periodLabel'    => $periodLabel,
        ]);
    }

    /**
     * Build the six month-over-month efficiency KPIs for the dashboard strip.
     * Each entry: ['label', 'value', 'prev', 'fmt'('days'|'pct'|'money'),
     * 'ccy', 'higher_better']. Returned in the user's requested order.
     */
    private function buildExtraKpis($client, int $year): array
    {
        $k = [];

        // EBITDA/EBIT margin + Revenue/Cost-per-scan. Monthly P&L from
        // PLForecastService (much lighter than the full ProfitabilityService);
        // per-scan denominators are the scan counts for ONLY the last two
        // closed months (not the whole year).
        try {
            $pl   = (new \App\Services\PLForecastService($client))->analyze($year);
            $ccy  = (string) ($pl['currency'] ?? 'XAF');
            $thru = (int) ($pl['thru_month'] ?? 0);
            $by   = [];
            foreach (($pl['lines'] ?? []) as $line) { $by[$line['key'] ?? ''] = $line['monthly'] ?? []; }
            $rev = $by['revenue'] ?? []; $eb = $by['ebitda'] ?? []; $ei = $by['ebit'] ?? [];
            if ($thru >= 2) {
                $a = $thru; $b = $thru - 1;                       // 1-indexed latest closed + prior
                $ra = (float) ($rev[$a] ?? 0); $rb = (float) ($rev[$b] ?? 0);
                $marg = fn ($num, $r) => $r != 0.0 ? (float) $num / $r * 100.0 : null;
                $k['ebitda_margin'] = ['label' => 'EBITDA margin', 'value' => $marg($eb[$a] ?? 0, $ra), 'prev' => $marg($eb[$b] ?? 0, $rb), 'fmt' => 'pct', 'higher_better' => true];
                $k['ebit_margin']   = ['label' => 'EBIT margin',   'value' => $marg($ei[$a] ?? 0, $ra), 'prev' => $marg($ei[$b] ?? 0, $rb), 'fmt' => 'pct', 'higher_better' => true];

                $scanForMonth = function (int $m) use ($client, $year): int {
                    $from = sprintf('%04d-%02d-01 00:00:00', $year, $m);
                    $to   = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)))->modify('last day of this month')->format('Y-m-d 23:59:59');
                    $w = [['scanned', '=', true], ['scanned_date', '>=', $from], ['scanned_date', '<=', $to]];
                    $t = 0;
                    try { $t += (int) $client->searchCount('aw_cargo.equipment', $w); } catch (\Throwable $e) {}
                    try { $t += (int) $client->searchCount('aw_vehicule.vehicule', $w); } catch (\Throwable $e) {}
                    try {
                        $g = $client->callModel('aw_bois.bois', 'read_group', ['domain' => $w, 'fields' => [], 'groupby' => ['cargo_id'], 'lazy' => false]);
                        $t += count(array_filter((array) $g, fn ($r) => !empty($r['cargo_id']) && $r['cargo_id'] !== false));
                    } catch (\Throwable $e) {}
                    return $t;
                };
                $sa = $scanForMonth($a); $sb = $scanForMonth($b);
                $costA = $ra - (float) ($ei[$a] ?? 0); $costB = $rb - (float) ($ei[$b] ?? 0);
                $k['cost_per_scan'] = ['label' => 'Cost / scan',    'value' => $sa > 0 ? $costA / $sa : null, 'prev' => $sb > 0 ? $costB / $sb : null, 'fmt' => 'money', 'ccy' => $ccy, 'higher_better' => false];
                $k['rev_per_scan']  = ['label' => 'Revenue / scan', 'value' => $sa > 0 ? $ra / $sa : null,    'prev' => $sb > 0 ? $rb / $sb : null,    'fmt' => 'money', 'ccy' => $ccy, 'higher_better' => true];
            }
        } catch (\Throwable $e) { /* leave those KPIs absent */ }

        // DSO + DPO — last two non-null points of the liquidity trend.
        try {
            $series = (new \App\Services\WorkingCapitalService($client))->liquidityTrend(12)['series'] ?? [];
            foreach (['dso' => ['DSO', false], 'dpo' => ['DPO', true]] as $key => [$label, $higherBetter]) {
                $pts = [];
                foreach ($series as $p) { if (($p[$key] ?? null) !== null) $pts[] = (float) $p[$key]; }
                $n = count($pts);
                if ($n >= 1) {
                    $k[$key] = ['label' => $label, 'value' => $pts[$n - 1], 'prev' => $n >= 2 ? $pts[$n - 2] : null, 'fmt' => 'days', 'higher_better' => $higherBetter];
                }
            }
        } catch (\Throwable $e) { /* leave DSO/DPO absent */ }

        // Return in the user's requested order.
        $out = [];
        foreach (['dso', 'dpo', 'cost_per_scan', 'rev_per_scan', 'ebitda_margin', 'ebit_margin'] as $key) {
            if (isset($k[$key])) $out[$key] = $k[$key];
        }
        return $out;
    }

    public function sync()
    {
        ea_cache_clear();
        return back()->with('flash', ['type' => 'success', 'msg' => 'Data refreshed — next load will hit Odoo live.']);
    }
}
