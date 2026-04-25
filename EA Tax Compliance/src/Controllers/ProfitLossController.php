<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\ProfitLossService;

final class ProfitLossController
{
    public function index(): void
    {
        ea_require_login();

        $period = [
            'type'  => $_GET['period'] ?? 'ytd',
            'year'  => $_GET['year']   ?? null,
            'month' => $_GET['month']  ?? null,
        ];
        $cacheKey = 'pl_' . md5(json_encode($period));

        $error = null;
        $pl    = null;

        try {
            $pl = ea_cached($cacheKey, function () use ($period) {
                $client = OdooFactory::fromConfig();
                return (new ProfitLossService($client))->analyze(null, $period);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('profit_loss', [
            'pl'        => $pl,
            'period'    => $period,
            'error'     => $error,
            'activeNav' => 'profit_loss',
            'pageTitle' => 'Profit & Loss',
        ]);
    }

    public function apiProfitLoss(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            $client = OdooFactory::fromConfig();
            echo json_encode((new ProfitLossService($client))->analyze(), JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
