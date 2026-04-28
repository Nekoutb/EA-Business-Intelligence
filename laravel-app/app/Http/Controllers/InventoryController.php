<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\InventoryService;
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
            $error = $e->getMessage();
        }

        return view('inventory', [
            'data'   => $data,
            'error'  => $error,
            'active' => 'inventory',
            'aiPage' => 'inventory',
        ]);
    }
}
