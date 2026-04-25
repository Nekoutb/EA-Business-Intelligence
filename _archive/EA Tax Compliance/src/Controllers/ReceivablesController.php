<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Config;
use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\ReceivablesService;
use EA\BI\Services\RevenueService;

final class ReceivablesController
{
    public function index(): void
    {
        ea_require_login();

        $error = null;
        $ar    = null;

        try {
            $ar = ea_cached('ar', function () {
                $client = OdooFactory::fromConfig();
                $source = (string) Config::get('REVENUE_SOURCE', 'invoice');

                // We need YTD revenue + days for the DSO calculation.
                $revenue    = (new RevenueService($client, $source))->analyze();
                $revenueYtd = (float) ($revenue['current']['total'] ?? 0.0);
                $days       = (int)   ($revenue['period']['days']   ?? 1);
                $currency   = (string)($revenue['currency']          ?? 'USD');

                return (new ReceivablesService($client))->analyze($revenueYtd, $days, $currency);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('receivables', [
            'ar'        => $ar,
            'error'     => $error,
            'activeNav' => 'receivables',
            'pageTitle' => 'Receivables',
        ]);
    }

    public function apiReceivables(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            $client = OdooFactory::fromConfig();
            $source = (string) Config::get('REVENUE_SOURCE', 'invoice');
            $revenue = (new RevenueService($client, $source))->analyze();
            $ar = (new ReceivablesService($client))->analyze(
                (float) ($revenue['current']['total'] ?? 0.0),
                (int)   ($revenue['period']['days']   ?? 1),
                (string)($revenue['currency']          ?? 'USD'),
            );
            echo json_encode($ar, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
