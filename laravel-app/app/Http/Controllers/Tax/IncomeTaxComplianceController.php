<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\IncomeTaxComplianceService;

class IncomeTaxComplianceController extends Controller
{
    public function index()
    {
        $analysis = null;
        $error    = null;
        try {
            $analysis = ea_cached('income_tax_compliance', function () {
                return (new IncomeTaxComplianceService(OdooFactory::fromConfig()))->analyze();
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.income-tax-compliance', [
            'analysis' => $analysis,
            'error'    => $error,
            'active'   => 'income_tax_compliance',
        ]);
    }
}
