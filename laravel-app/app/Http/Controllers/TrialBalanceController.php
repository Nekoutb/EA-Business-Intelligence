<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\TrialBalanceService;
use Illuminate\Http\Request;

/**
 * Trial Balance — Balance Générale per OHADA. Year + month + period
 * type filters. Cached per-tenant per-selection so flipping months is
 * instant after first hit.
 *
 * Period plumbing — the master filter in layouts/app.blade.php submits
 * `period` (not `period_type`); the RememberPeriod middleware persists
 * the choice in session and merges it back into every request. So we
 * read `period` here, same as every other BI controller, then map the
 * master types {ytd, mtd, month, yoy} onto the trial balance's internal
 * vocabulary {ytd, month, year}.
 *
 * History note: read `period_type` from the URL until 2026-05-11 — a
 * key name no caller ever provided, so the filter never updated and
 * the page was stuck on YTD regardless of the master filter selection.
 */
class TrialBalanceController extends Controller
{
    public function index(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $year   = (int) $request->query('year', $today->format('Y'));
        $thisYear = (int) $today->format('Y');
        $year   = max(2018, min($thisYear + 1, $year));

        $month  = (int) $request->query('month', max(1, ((int) $today->format('n')) - 1));
        $month  = max(1, min(12, $month));

        // Master filter value (matches what every other controller reads).
        $masterPeriod = strtolower((string) $request->query('period', 'ytd'));

        // Trial Balance has its own vocabulary — translate from master:
        //   ytd   → ytd    cumulative YTD through the selected month (default)
        //   mtd   → month  current month — partial-MTD has no different
        //                  meaning for a balance, render the month view
        //   month → month  specific month
        //   yoy   → ytd    YoY is a comparison concept that doesn't apply
        //                  to a point-in-time TB; the YoY button is also
        //                  frozen on this page in trial-balance.blade.php
        //                  (@section('mfilter-frozen', 'yoy')) so the user
        //                  can't actively pick it — this default is the
        //                  safety net for any legacy session value.
        $periodType = match ($masterPeriod) {
            'mtd'   => 'month',
            'month' => 'month',
            default => 'ytd',
        };

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            // Cache prefix bumped to v2 on 2026-05-11: the service now
            // computes opening balances for the month view (it used to
            // return zero opening for 'month'). v1 entries would still
            // serve the broken figures for up to 5 minutes after deploy.
            $cacheKey = "trial_balance_v2_{$periodType}_{$year}_{$month}";
            $data = ea_cached(
                $cacheKey,
                fn () => (new TrialBalanceService($client))->analyze($year, $periodType, $month)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('trial-balance', [
            'active'      => 'trial_balance',
            'year'        => $year,
            'month'       => $month,
            'periodType'  => $periodType,
            'data'        => $data,
            'error'       => $error,
        ]);
    }
}
