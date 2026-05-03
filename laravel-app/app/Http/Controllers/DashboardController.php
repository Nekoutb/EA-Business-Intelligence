<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\DashboardKpiService;
use Illuminate\Http\Request;

/**
 * Dashboard — top-management single-pulse page.
 *
 * Renders 11 KPIs in 3 themes (Financial 4 / Operational 4 / Compliance 3),
 * all sourced through DashboardKpiService — which itself composes the
 * existing per-module services (Operations, Billing, OHADA, Receivables,
 * Procurement, GLQuality, VAT, Monthly Tax Return). No new GL queries.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) $type = 'ytd';

        $year   = (int) $request->query('year',  $today->format('Y'));
        $month  = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [, , $periodLabel] = ea_resolve_period($today, $period);

        $cacheTag = "dashboard_v5_{$type}_{$year}_{$month}";
        $analysis = null;
        $error    = null;

        try {
            $client   = OdooFactory::fromConfig();
            $analysis = ea_cached($cacheTag,
                fn () => (new DashboardKpiService($client))->computeAll($period, $today)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('dashboard', [
            'analysis'    => $analysis,
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

    /**
     * Design-system preview — same KPIs as index(), rendered through
     * dashboard-preview.blade.php (Elite Advisors kit tokens, palette
     * switcher, no app shell). Lets the principal compare the kit-styled
     * cut against the live page before we roll out across the suite.
     */
    public function preview(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) $type = 'ytd';

        $year   = (int) $request->query('year',  $today->format('Y'));
        $month  = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [, , $periodLabel] = ea_resolve_period($today, $period);

        $cacheTag = "dashboard_v5_{$type}_{$year}_{$month}";
        $analysis = null;
        $error    = null;

        try {
            $client   = OdooFactory::fromConfig();
            $analysis = ea_cached($cacheTag,
                fn () => (new \App\Services\DashboardKpiService($client))->computeAll($period, $today)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('dashboard-preview', [
            'analysis'    => $analysis,
            'error'       => $error,
            'period'      => $period,
            'periodLabel' => $periodLabel,
        ]);
    }
}
