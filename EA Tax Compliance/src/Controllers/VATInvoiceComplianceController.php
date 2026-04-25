<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\VATInvoiceComplianceService;

final class VATInvoiceComplianceController
{
    public function index(): void
    {
        ea_require_login();

        $period = [
            'type'  => $_GET['period'] ?? 'ytd',
            'year'  => $_GET['year']   ?? null,
            'month' => $_GET['month']  ?? null,
        ];
        $cacheKey = 'vat_invoice_compliance_' . md5(json_encode($period));

        $error = null;
        $analysis = null;

        try {
            $svc = $this->makeService();
            $analysis = ea_cached($cacheKey, fn() => $svc->analyze(null, $period));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('vat_invoice_compliance', [
            'analysis'  => $analysis,
            'period'    => $period,
            'error'     => $error,
            'activeNav' => 'vat-invoice-compliance',
            'pageTitle' => 'VAT Invoice Compliance',
        ]);
    }

    public function apiVATInvoiceCompliance(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            $analysis = $this->makeService()->analyze();
            echo json_encode($analysis, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------

    private function makeService(): VATInvoiceComplianceService
    {
        $client = OdooFactory::fromConfig();
        return new VATInvoiceComplianceService($client);
    }
}
