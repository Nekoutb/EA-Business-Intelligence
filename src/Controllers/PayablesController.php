<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\PayablesService;
use EA\BI\Services\ProfitLossService;

final class PayablesController
{
    public function index(): void
    {
        ea_require_login();

        $error = null;
        $ap    = null;

        try {
            $ap = ea_cached('ap', function () {
                $client = OdooFactory::fromConfig();
                // Total expenses drive DPO. Fall back to 0 (DPO=null) if P&L access fails.
                $totalExpenses = 0.0;
                $currency      = 'USD';
                try {
                    $pl = (new ProfitLossService($client))->analyze();
                    $totalExpenses = (float) (($pl['current']['cogs'] ?? 0) + ($pl['current']['opex'] ?? 0) + ($pl['current']['d_and_a'] ?? 0));
                    $currency      = (string) ($pl['currency'] ?? 'USD');
                } catch (\Throwable $e) { /* keep defaults */ }
                return (new PayablesService($client))->analyze($totalExpenses, 365, $currency);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('payables', [
            'ap'        => $ap,
            'error'     => $error,
            'activeNav' => 'payables',
            'pageTitle' => 'Payables',
        ]);
    }

    public function apiPayables(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            $client = OdooFactory::fromConfig();
            $totalExpenses = 0.0;
            $currency      = 'USD';
            try {
                $pl = (new ProfitLossService($client))->analyze();
                $totalExpenses = (float) (($pl['current']['cogs'] ?? 0) + ($pl['current']['opex'] ?? 0) + ($pl['current']['d_and_a'] ?? 0));
                $currency      = (string) ($pl['currency'] ?? 'USD');
            } catch (\Throwable $e) { /* ignore */ }
            echo json_encode((new PayablesService($client))->analyze($totalExpenses, 365, $currency), JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
