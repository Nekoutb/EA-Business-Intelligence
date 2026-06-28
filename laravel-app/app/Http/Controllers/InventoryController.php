<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\InventoryService;
use App\Services\ReportsService;
use App\Services\StockOperationsService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        // The Reports section is hosted on this already-cached route as
        // /inventory?view=reports (a dedicated /reports route 404s behind this
        // host's frozen route cache). Rendered through inventory.blade in
        // reportsMode so we don't need a new compiled view either.
        if ($request->query('view') === 'reports') {
            return $this->reportsView($request);
        }

        // Inventory page is YTD-only by design — no period parameter is read.
        // The cache key reflects that scope (per-tenant via ea_cached helper).
        $today = new \DateTimeImmutable();

        $data  = null;
        $error = null;
        try {
            $data = ea_cached('inventory_ytd', function () use ($today) {
                $svc = new InventoryService(OdooFactory::fromConfig());
                return $svc->analyze($today);
            });
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'inventory_gl', 'Could not load inventory data from Odoo. Check the connection in Settings.');
        }

        // Stock operations (warehouse-level view) — cached and error-isolated
        // separately so the GL boxes above still render if the stock models
        // are unavailable or the section fails.
        $stock      = null;
        $stockError = null;
        try {
            $stock = ea_cached('inventory_stock_ops', function () use ($today) {
                $svc = new StockOperationsService(OdooFactory::fromConfig());
                return $svc->analyze($today);
            });
        } catch (\Throwable $e) {
            $stockError = ea_safe_error($e, 'inventory_stock_ops', 'Stock-level data could not be loaded from Odoo right now.');
        }

        return view('inventory', [
            'data'       => $data,
            'error'      => $error,
            'stock'      => $stock,
            'stockError' => $stockError,
            'active'     => 'inventory',
            'aiPage'     => 'inventory',
        ]);
    }

    /**
     * Reports — day-level report (calendar → daily sales + inventory movements),
     * hosted on this route and rendered through inventory.blade (reportsMode).
     * Mirror of ReportsController::index; both stay so the clean /reports route
     * works the moment the route cache is cleared.
     */
    private function reportsView(Request $request)
    {
        $today = new \DateTimeImmutable();

        $date = (string) $request->query('date', $today->format('Y-m-d'));
        try { $d = new \DateTimeImmutable($date); } catch (\Throwable $e) { $d = $today; }
        $date = $d->format('Y-m-d');

        $month = max(1, min(12, (int) $request->query('month', $d->format('n'))));
        $year  = max(2018, min((int) $today->format('Y') + 1, (int) $request->query('year', $d->format('Y'))));

        $sales = null; $inventory = null; $activity = []; $currency = 'XAF'; $error = null;
        try {
            $svc = new ReportsService(OdooFactory::fromConfig());
            $currency  = ea_cached('reports_ccy', fn () => $svc->currency());
            $sales     = ea_cached("reports_sales_{$date}",        fn () => $svc->dailySales($date));
            $inventory = ea_cached("reports_inv_{$date}",          fn () => $svc->dailyInventory($date));
            $activity  = ea_cached("reports_act_{$year}_{$month}", fn () => $svc->monthActivity($year, $month));
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'reports.index', 'Could not load report data from Odoo. Check the connection in Settings.');
        }

        return view('inventory', [
            'reportsMode' => true,
            'active'      => 'reports',
            'aiPage'      => 'reports',
            'date'        => $date,
            'month'       => $month,
            'year'        => $year,
            'sales'       => $sales,
            'inventory'   => $inventory,
            'activity'    => $activity,
            'currency'    => $currency,
            'error'       => $error,
            // Normal-inventory vars kept defined so the @else branch never trips
            // if it is somehow reached.
            'data'        => null,
            'stock'       => null,
            'stockError'  => null,
        ]);
    }
}
