<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\VATComplianceService;

class VATComplianceController extends Controller
{
    public function index()
    {
        $analysis = null;
        $error    = null;
        try {
            $analysis = ea_cached('vat_compliance', function () {
                return (new VATComplianceService(OdooFactory::fromConfig()))->analyze();
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.vat-compliance', [
            'analysis' => $analysis,
            'error'    => $error,
            'active'   => 'vat_compliance',
            'aiPage'   => 'vat_compliance',
        ]);
    }

    public function calendar()
    {
        // The 52-week tax calendar is a render-only view of the same regime
        // determined by VATComplianceService; we reuse the analysis to label
        // the page and pick regime-specific calendar entries.
        $analysis = null;
        $error    = null;
        try {
            $analysis = ea_cached('vat_compliance', function () {
                return (new VATComplianceService(OdooFactory::fromConfig()))->analyze();
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.tax-calendar', [
            'analysis' => $analysis,
            'error'    => $error,
            'active'   => 'tax_calendar',
        ]);
    }
}
