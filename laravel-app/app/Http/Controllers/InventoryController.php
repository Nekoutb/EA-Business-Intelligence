<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\InventoryService;
use App\Services\StockOperationsService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
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
}
