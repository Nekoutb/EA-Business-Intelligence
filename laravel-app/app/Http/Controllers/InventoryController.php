<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $today = now();
        $period = [
            'type'  => $request->query('period', 'ytd'),
            'year'  => (int) $request->query('year', $today->format('Y')),
            'month' => (int) $request->query('month', $today->format('m')),
        ];

        $cacheKey = 'inventory_' . md5(json_encode($period));
        $data = ea_cached($cacheKey, function () use ($today, $period) {
            $svc = new InventoryService(OdooFactory::fromConfig());
            return $svc->analyze($today, $period);
        });

        return view('inventory', [
            'data'   => $data,
            'active' => 'inventory',
            'aiPage' => 'inventory',
        ]);
    }
}
