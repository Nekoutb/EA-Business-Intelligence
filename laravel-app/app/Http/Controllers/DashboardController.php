<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ProfitLossService;
use App\Services\RevenueService;
use App\Services\ReceivablesService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Accept ?period=ytd|mtd via the period-filter component. Default
        // YTD because that's the cumulative read most users want first.
        // We deliberately don't expose Month/YoY here (Revenue page is the
        // power-user surface) — keep the dashboard's filter to the two
        // primary timeframes that matter for "how is the year going so far?"
        // (YTD) vs "how is this month tracking?" (MTD).
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd'], true)) {
            $type = 'ytd';
        }
        $period = ['type' => $type];
        [, , $periodLabel] = ea_resolve_period($today, $period);

        // Period-scoped cache keys so YTD and MTD don't clobber each other.
        $cacheTag = $type;

        $source       = (string) config_env('REVENUE_SOURCE', 'invoice');
        $data         = null;
        $pl           = null;
        $receivables  = null;
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
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('dashboard', [
            'data'        => $data,
            'pl'          => $pl,
            'receivables' => $receivables,
            'error'       => $error,
            'active'      => 'dashboard',
            'period'      => $period,
            'periodLabel' => $periodLabel,
        ]);
    }

    public function sync()
    {
        ea_cache_clear();
        return back()->with('flash', ['type' => 'success', 'msg' => 'Data refreshed — next load will hit Odoo live.']);
    }
}
