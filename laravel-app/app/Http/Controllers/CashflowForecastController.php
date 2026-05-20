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
     * fails entirely. Renders the empty chart container + matrix
     * skeleton so the user can see the page structure and read the
     * error banner above, rather than seeing a blank page that
     * looks broken.
     *
     * @return array<string, mixed>
     */
    private function zeroState16w(Carbon $cutOff): array
    {
        $weeks = [];
        $cursor = $cutOff->copy()->startOfDay();
        for ($i = 0; $i < CashFlow16WService::HORIZON_WEEKS; $i++) {
            $start = $cursor->copy()->addDays($i * 7);
            $end   = $start->copy()->addDays(6);
            $weeks[] = [
                'w_no' => $i + 1, 'label' => 'W' . ($i + 1),
                'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                'opening' => 0.0, 'closing' => 0.0, 'net' => 0.0,
                'receipts' => 0.0, 'disbursements' => 0.0, 'financing' => 0.0,
            ];
        }
        return [
            'currency' => 'XAF',
            'cut_off'  => $cutOff->format('Y-m-d'),
            'weeks'    => $weeks,
            'lines'    => [],
            'kpis'     => [
                'opening_w1'       => 0, 'projected_w16' => 0,
                'lowest_balance'   => 0, 'lowest_week_no' => 0,
                'lowest_under_min' => 0, 'buffer_breaches' => 0,
                'horizon_delta'    => 0, 'min_buffer' => (float) config_env('TREASURY_MIN_BUFFER_XAF', 8_000_000),
            ],
            'governance' => [
                'method' => 'Direct · Method C (hybrid AR/AP timing)',
            ],
            'errors' => [],
        ];
    }
}
