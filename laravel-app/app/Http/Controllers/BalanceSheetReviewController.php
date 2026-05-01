<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\OhadaFinancialsService;
use Illuminate\Http\Request;

/**
 * Balance Sheet Review — Bilan Actif / Bilan Passif per OHADA
 * Système Normal. Pulls closing balances from the Trial Balance,
 * maps them to BS lines, and renders a side-by-side review.
 */
class BalanceSheetReviewController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));
        $thisYear = (int) $today->format('Y');
        $year = max(2018, min($thisYear + 1, $year));

        $month = (int) $request->query('month', max(1, ((int) $today->format('n')) - 1));
        $month = max(1, min(12, $month));

        $periodType = strtolower((string) $request->query('period_type', 'ytd'));
        if (!in_array($periodType, ['month', 'ytd', 'year'], true)) $periodType = 'ytd';

        $data = null; $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "balance_sheet_review_{$periodType}_{$year}_{$month}";
            $data = ea_cached(
                $cacheKey,
                fn () => (new OhadaFinancialsService($client))->analyze($year, $periodType, $month)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('balance-sheet-review', [
            'active'     => 'balance_sheet',
            'year'       => $year,
            'month'      => $month,
            'periodType' => $periodType,
            'data'       => $data,
            'error'      => $error,
        ]);
    }
}
