<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\OhadaFinancialsService;
use Illuminate\Http\Request;

/**
 * OHADA Financial Statements — full Système Normal package.
 * Currently includes:
 *   - Bilan (Actif + Passif)
 *   - Compte de Résultat (with derived subtotals)
 * TAFIRE / cash flow comes via the existing CashflowForecastService.
 */
class OhadaFinancialStatementsController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));
        $thisYear = (int) $today->format('Y');
        $year = max(2018, min($thisYear + 1, $year));

        $month = (int) $request->query('month', max(1, ((int) $today->format('n')) - 1));
        $month = max(1, min(12, $month));

        $periodType = strtolower((string) $request->query('period_type', 'year'));
        if (!in_array($periodType, ['month', 'ytd', 'year'], true)) $periodType = 'year';

        $data = null; $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "ohada_financials_{$periodType}_{$year}_{$month}";
            $data = ea_cached(
                $cacheKey,
                fn () => (new OhadaFinancialsService($client))->analyze($year, $periodType, $month)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('ohada-financial-statements', [
            'active'     => 'income_statement', // matches existing module 'active' key
            'year'       => $year,
            'month'      => $month,
            'periodType' => $periodType,
            'data'       => $data,
            'error'      => $error,
        ]);
    }
}
