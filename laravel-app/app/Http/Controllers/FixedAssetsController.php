<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\FixedAssetsService;

class FixedAssetsController extends Controller
{
    public function index()
    {
        $data = null; $error = null;
        try {
            $data = ea_cached('fa_v2', fn() =>
                (new FixedAssetsService(OdooFactory::fromConfig()))->analyze());
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('fixed-assets', [
            'data' => $data, 'error' => $error,
            'active' => 'fixed_assets', 'aiPage' => 'fixed_assets',
        ]);
    }
}
