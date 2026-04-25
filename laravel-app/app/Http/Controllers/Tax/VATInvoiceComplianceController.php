<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\VATInvoiceComplianceService;
use Illuminate\Http\Request;

class VATInvoiceComplianceController extends Controller
{
    public function index(Request $request)
    {
        $period = [
            'type'  => $request->query('period', 'ytd'),
            'year'  => $request->query('year'),
            'month' => $request->query('month'),
        ];
        $cacheKey = 'vat_invoice_compliance_' . md5(json_encode($period));

        $analysis = null;
        $error    = null;
        try {
            $analysis = ea_cached($cacheKey, function () use ($period) {
                return (new VATInvoiceComplianceService(OdooFactory::fromConfig()))
                    ->analyze(null, $period);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.vat-invoice-compliance', [
            'analysis' => $analysis,
            'period'   => $period,
            'error'    => $error,
            'active'   => 'vat_invoice_compliance',
            'aiPage'   => 'vat_invoice_compliance',
        ]);
    }
}
