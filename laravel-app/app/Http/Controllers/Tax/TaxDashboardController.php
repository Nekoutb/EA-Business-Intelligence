<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\TaxDashboardService;

/**
 * Tax Dashboard — overview of regime, ETR, and obligations calendar.
 * Was VATComplianceController prior to the 2026-04-27 reshuffle.
 */
class TaxDashboardController extends Controller
{
    public function index()
    {
        $analysis = null;
        $error    = null;
        try {
            $analysis = ea_cached('tax_dashboard', function () {
                return (new TaxDashboardService(OdooFactory::fromConfig()))->analyze();
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.tax-dashboard', [
            'analysis' => $analysis,
            'error'    => $error,
            'active'   => 'tax_dashboard',
        ]);
    }
}
