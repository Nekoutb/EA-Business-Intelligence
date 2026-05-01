<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\TrialBalanceService;
use Illuminate\Http\Request;

/**
 * Trial Balance — Balance Générale per OHADA. Year + month + period
 * type filters. Cached per-tenant per-selection so flipping months is
 * instant after first hit.
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

        $periodType = strtolower((string) $request->query('period_type', 'ytd'));
        if (!in_array($periodType, ['month', 'ytd', 'year'], true)) $periodType = 'ytd';

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "trial_balance_{$periodType}_{$year}_{$month}";
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
