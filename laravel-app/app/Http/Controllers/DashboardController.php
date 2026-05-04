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
        $error        = null;

        try {
            $client = OdooFactory::fromConfig();
            $data   = ea_cached("revenue_{$cacheTag}",
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
            try {
                $reconciliation = ea_cached("recon_{$cacheTag}",
                    fn () => (new BillingService($client))->reconciliationOnly(null, $period));
            } catch (\Throwable $e) {
                $reconciliation = null;
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
            'error'          => $error,
            'active'         => 'dashboard',
            'period'         => $period,
            'periodLabel'    => $periodLabel,
        ]);
    }

    public function sync()
    {
        ea_cache_clear();
        return back()->with('flash', ['type' => 'success', 'msg' => 'Data refreshed — next load will hit Odoo live.']);
    }
}
