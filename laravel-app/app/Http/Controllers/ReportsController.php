<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ReportsService;
use Illuminate\Http\Request;

/**
 * Reports — day-level operational report. A calendar selects a date; the
 * sandboxes below disclose that day's sales (cash vs credit) and inventory
 * movements (valued at standard cost). Cached per (tenant, date/month).
 */
class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();

        // Selected date (defaults to today). Validate / clamp to a real date.
        $date = (string) $request->query('date', $today->format('Y-m-d'));
        try { $d = new \DateTimeImmutable($date); } catch (\Throwable $e) { $d = $today; }
        $date = $d->format('Y-m-d');

        // Calendar month/year (defaults to the selected date's month).
        $month = (int) $request->query('month', $d->format('n'));
        $year  = (int) $request->query('year',  $d->format('Y'));
        $month = max(1, min(12, $month));
        $year  = max(2018, min((int) $today->format('Y') + 1, $year));

        $sales = null; $inventory = null; $activity = []; $currency = 'XAF'; $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $svc    = new ReportsService($client);
            $currency  = ea_cached('reports_ccy', fn () => $svc->currency());
            $sales     = ea_cached("reports_sales_{$date}",         fn () => $svc->dailySales($date));
            $inventory = ea_cached("reports_inv_{$date}",           fn () => $svc->dailyInventory($date));
            $activity  = ea_cached("reports_act_{$year}_{$month}",  fn () => $svc->monthActivity($year, $month));
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'reports.index', 'Could not load report data from Odoo. Check the connection in Settings.');
        }

        return view('reports', [
            'active'    => 'reports',
            'date'      => $date,
            'month'     => $month,
            'year'      => $year,
            'sales'     => $sales,
            'inventory' => $inventory,
            'activity'  => $activity,
            'currency'  => $currency,
            'error'     => $error,
        ]);
    }
}
