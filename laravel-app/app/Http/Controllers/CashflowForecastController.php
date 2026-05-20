<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\CashFlow16WService;
use App\Services\CashflowForecastService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Cashflow Forecast page — hosts both:
 *
 *   1. 16-week direct-method weekly forecast (Mercer-style matrix
 *      with KPI strip, chart, full band/line breakdown). Built via
 *      CashFlow16WService against live Odoo data.
 *
 *   2. Annual indirect-via-direct monthly forecast (the original
 *      analysis based on class-5 movements). Built via
 *      CashflowForecastService.
 *
 * Both load independently — one's failure doesn't kill the other.
 */
class CashflowForecastController extends Controller
{
    public function index(Request $request)
    {
        $today    = new \DateTimeImmutable();
        $year     = (int) $request->query('year', $today->format('Y'));
        $thisYear = (int) $today->format('Y');
        $year     = max(2018, min($thisYear + 2, $year));

        $data    = null;
        $data16w = null;
        $error   = null;

        // Annual monthly forecast
        try {
            $client = OdooFactory::fromConfig();
            $data = ea_cached(
                "cashflow_forecast_{$year}",
                fn () => (new CashflowForecastService($client))->analyze($year)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // 16-week weekly forecast. Two-layer error handling:
        //   Layer 1: service-level try/catch (Odoo total failure)
        //   Layer 2: each line uses safe() internally so a per-line
        //            data-source gap surfaces as a warning, not a
        //            blank section.
        //
        // CRITICAL: even on total Odoo failure we want the user to see
        // the page structure (chart container + matrix) so the page
        // is clearly alive. We synthesise a zero-state forecast and
        // keep the error as a non-fatal notice rendered above the
        // section.
        $cutOff   = Carbon::now();
        $error16w = null;
        try {
            $cacheKey = 'cashflow16w_' . $cutOff->format('Y-m-d');
            if ($request->boolean('refresh16w')) {
                $cacheKey .= '_' . now()->format('Hi');
            }
            $data16w = ea_cached(
                $cacheKey,
                fn () => (new CashFlow16WService(OdooFactory::fromConfig()))->build($cutOff)
            );
        } catch (\Throwable $e) {
            $error16w = $e->getMessage();
            // Render with a synthetic zero-state forecast so the
            // page never goes blank.
            $data16w = $this->zeroState16w($cutOff);
        }

        return view('cashflow-forecast', [
            'active'   => 'cashflow_forecast',
            'aiPage'   => 'cashflow_forecast',
            'year'     => $year,
            'data'     => $data,
            'data16w'  => $data16w,
            'error16w' => $error16w,
            'error'    => $error,
        ]);
    }

    /**
     * Synthetic zero-state forecast — used when Odoo extraction
     * fails entirely. Renders the full matrix STRUCTURE with all
     * line labels at zero so the user sees the page they expect,
     * not a near-empty page that looks broken.
     *
     * @return array<string, mixed>
     */
    private function zeroState16w(Carbon $cutOff): array
    {
        $horizonWeeks = CashFlow16WService::HORIZON_WEEKS;
        $weeks = [];
        $cursor = $cutOff->copy()->startOfDay();
        for ($i = 0; $i < $horizonWeeks; $i++) {
            $start = $cursor->copy()->addDays($i * 7);
            $end   = $start->copy()->addDays(6);
            $weeks[] = [
                'w_no' => $i + 1, 'label' => 'W' . ($i + 1),
                'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                'opening' => 0.0, 'closing' => 0.0, 'net' => 0.0,
                'receipts' => 0.0, 'disbursements' => 0.0, 'financing' => 0.0,
            ];
        }

        // Build the line shells so the matrix renders the full
        // band/line structure even at zero. Without this the
        // matrix shows only Band 5 (closing trajectory) and the
        // user thinks the page is broken.
        $zeroByWeek = array_fill(0, $horizonWeeks, 0.0);
        $L = fn (int $band, string $line, string $label, string $eng, ?string $conf, int $sign, string $src) => [
            'band' => $band, 'line' => $line, 'label' => $label, 'engine' => $eng,
            'confidence' => $conf, 'total' => 0.0, 'by_week' => $zeroByWeek,
            'sign' => $sign, 'source' => $src,
        ];
        $lines = [
            $L(1, '1.1', 'Opening cash · banks',                'A', 'High',   1, 'class 52 GL balance — Odoo unreachable'),
            $L(1, '1.2', 'Opening cash · mobile money',         'A', 'High',   1, 'class 52 mobile sub-accts'),
            $L(1, '1.3', 'Opening cash · caisse (petty)',       'A', 'High',   1, 'class 57 GL balance'),
            $L(1, '1.4', '− Minimum operational reserve',       'D', 'Policy',-1, 'TREASURY_MIN_BUFFER_XAF setting'),
            $L(1, '1.5', '= Available opening liquidity',       '=',  null,    1, 'derived'),
            $L(2, '2.1', 'AR collections · confirmed invoices', 'B', 'High',   1, 'account.move out_invoice'),
            $L(2, '2.2', 'AR · recurring contracts',            'C', 'High',   1, 'sale.subscription — Phase 2'),
            $L(2, '2.3', 'Cash sales / POS',                    'B', 'Medium', 1, 'pos.order or class 70 fallback'),
            $L(2, '2.4', 'Sales pipeline (weighted)',           'B', 'Low',    1, 'crm.lead'),
            $L(2, '2.5', 'VAT refunds (crédit TVA)',            'C', 'Low',    1, 'Phase 2'),
            $L(2, '2.6', 'Other operating receipts',            'D', null,     1, 'manual entry'),
            $L(2, '2.7', 'Asset disposals',                     'D', null,     1, 'planned disposals'),
            $L(2, '2.8', 'Tax withholding refunds (précompte)', 'C', null,     1, 'typically nil'),
            $L(3, '3.1', 'AP payments · confirmed invoices',    'B', 'High',  -1, 'account.move in_invoice'),
            $L(3, '3.2', 'AP payments · PO-driven',             'C', 'Medium',-1, 'purchase.order or class 60 fallback'),
            $L(3, '3.3', 'Payroll · gross salaries',            'C', 'High',  -1, 'hr.payslip or CLASS 66 fallback'),
            $L(3, '3.4', 'CNPS contributions',                  'C', 'High',  -1, 'derived from payroll'),
            $L(3, '3.5', 'IRPP withheld remittance',            'C', 'High',  -1, 'derived from payroll'),
            $L(3, '3.6', 'TVA collectée nette',                 'C', 'High',  -1, 'class 44'),
            $L(3, '3.7', 'Acompte IS (1.1% turnover)',          'C', 'High',  -1, 'class 70 × 1.1%'),
            $L(3, '3.8', 'Précompte sur achats remittance',     'C', null,    -1, 'Phase 2'),
            $L(3, '3.9', 'TSR (non-resident WHT)',              'C', null,    -1, 'Phase 2'),
            $L(3, '3.10','Patente / local taxes',               'C', null,    -1, 'Phase 2'),
            $L(3, '3.11','Taxe foncière',                       'C', null,    -1, 'Phase 2'),
            $L(3, '3.12','Operating cash expenses · rent/utils','C', 'High',  -1, 'class 61+62+63 fallback'),
            $L(3, '3.13','Petty cash drawdowns',                'B', 'Medium',-1, 'derived 52→57 transfers'),
            $L(3, '3.14','Customer refunds',                    'B', 'Low',   -1, 'out_refund + estimate'),
            $L(4, '4.1', 'Interest payments by facility',       'C', null,    -1, 'Phase 2 — treasury.debt.facility'),
            $L(4, '4.2', 'Principal repayments by facility',    'C', null,    -1, 'Phase 2 — treasury.debt.facility'),
            $L(4, '4.3', 'New debt drawdowns',                  'D', null,     1, 'manual'),
            $L(4, '4.4', 'Overdraft swings',                    'A', null,     1, 'derived'),
            $L(4, '4.5', 'CAPEX disbursements',                 'C', 'Medium',-1, 'purchase.order is_capex'),
            $L(4, '4.6', 'Dividend payments',                   'D', null,    -1, 'manual'),
            $L(4, '4.7', 'Shareholder contributions / loans',   'D', null,     1, 'manual'),
            $L(4, '4.8', 'Intercompany transfers',              'C', null,     1, 'multi-co'),
        ];

        return [
            'currency' => 'XAF',
            'cut_off'  => $cutOff->format('Y-m-d'),
            'weeks'    => $weeks,
            'lines'    => $lines,
            'kpis'     => [
                'opening_w1'       => 0, 'projected_w16' => 0,
                'lowest_balance'   => 0, 'lowest_week_no' => 0,
                'lowest_under_min' => 0, 'buffer_breaches' => 0,
                'horizon_delta'    => 0,
                'min_buffer'       => (float) config_env('TREASURY_MIN_BUFFER_XAF', 8_000_000),
            ],
            'governance' => [
                'method'             => 'Direct · Method C (hybrid AR/AP timing)',
                'data_freshness'     => 'Odoo unreachable — zero state',
                'manual_override_count' => 0,
            ],
            'errors'      => [],
            '_zero_state' => true,
        ];
    }
}
