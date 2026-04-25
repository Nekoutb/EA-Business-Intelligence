<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\VATComplianceService;

final class VATComplianceController
{
    public function index(): void
    {
        ea_require_login();

        $error = null;
        $analysis = null;

        try {
            $svc = $this->makeService();
            $analysis = ea_cached('vat_compliance', fn() => $svc->analyze());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('vat_compliance_dashboard', [
            'analysis'  => $analysis,
            'error'     => $error,
            'activeNav' => 'vat-compliance-dashboard',
            'pageTitle' => 'VAT Compliance Dashboard',
        ]);
    }

    public function apiVATCompliance(): void
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

    private function makeService(): VATComplianceService
    {
        $client = OdooFactory::fromConfig();
        return new VATComplianceService($client);
    }
}
