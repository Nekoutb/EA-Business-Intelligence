<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use EA\BI\Controllers\AIController;
use EA\BI\Controllers\AuthController;
use EA\BI\Controllers\DashboardController;
use EA\BI\Controllers\FixedAssetsController;
use EA\BI\Controllers\PayablesController;
use EA\BI\Controllers\ProfitLossController;
use EA\BI\Controllers\ReceivablesController;
use EA\BI\Controllers\SettingsController;
use EA\BI\Controllers\SyncController;
use EA\BI\Controllers\VATComplianceController;
use EA\BI\Controllers\VATInvoiceComplianceController;

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$routes = [
    ['GET',  '/',                  [DashboardController::class, 'index']],
    ['GET',  '/revenue',           [DashboardController::class, 'revenue']],
    ['GET',  '/receivables',       [ReceivablesController::class, 'index']],
    ['GET',  '/profit-loss',       [ProfitLossController::class, 'index']],
    ['GET',  '/payables',          [PayablesController::class, 'index']],
    ['GET',  '/fixed-assets',      [FixedAssetsController::class, 'index']],
    ['GET',  '/vat-compliance',    [VATComplianceController::class, 'index']],
    ['GET',  '/vat-compliance-dashboard', [VATComplianceController::class, 'index']],
    ['GET',  '/vat-invoice-compliance',   [VATInvoiceComplianceController::class, 'index']],
    ['POST', '/sync',              [SyncController::class, 'sync']],
    ['GET',  '/login',             [AuthController::class, 'showLogin']],
    ['POST', '/login',             [AuthController::class, 'doLogin']],
    ['GET',  '/logout',            [AuthController::class, 'logout']],
    ['GET',  '/settings',          [SettingsController::class, 'show']],
    ['POST', '/settings',          [SettingsController::class, 'save']],
    ['POST', '/settings/test',     [SettingsController::class, 'testConnection']],
    ['GET',  '/api/revenue',       [DashboardController::class, 'apiRevenue']],
    ['GET',  '/api/receivables',   [ReceivablesController::class, 'apiReceivables']],
    ['GET',  '/api/profit-loss',   [ProfitLossController::class, 'apiProfitLoss']],
    ['GET',  '/api/payables',      [PayablesController::class, 'apiPayables']],
    ['GET',  '/api/fixed-assets',  [FixedAssetsController::class, 'apiFixedAssets']],
    ['GET',  '/api/vat-compliance', [VATComplianceController::class, 'apiVATCompliance']],
    ['GET',  '/api/vat-invoice-compliance', [VATInvoiceComplianceController::class, 'apiVATInvoiceCompliance']],
    ['GET',  '/api/insights',      [AIController::class, 'insights']],
];

foreach ($routes as [$m, $p, $handler]) {
    if ($m === $method && $p === $path) {
        [$class, $action] = $handler;
        (new $class())->$action();
        return;
    }
}

http_response_code(404);
echo '<!doctype html><meta charset="utf-8"><title>404</title><body style="font-family:sans-serif;padding:40px"><h1>404 Not Found</h1><p><a href="/">Home</a></p></body>';
