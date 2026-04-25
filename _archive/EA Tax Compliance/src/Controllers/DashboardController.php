<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Config;
use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\ProfitLossService;
use EA\BI\Services\RevenueService;

final class DashboardController
{
    public function index(): void
    {
        ea_require_login();

        $error = null;
        $analysis = null;
        $pl       = null;

        try {
            $analysis = ea_cached('revenue_ytd', fn() => $this->makeService()->analyze());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // P&L snapshot is best-effort on the dashboard: if it fails (e.g. API
        // key lacks accounting read rights) we still render revenue KPIs.
        try {
            $pl = ea_cached('pl', function () {
                return (new ProfitLossService(OdooFactory::fromConfig()))->analyze();
            });
        } catch (\Throwable $e) {
            $pl = null;
        }

        ea_render('dashboard', [
            'analysis'  => $analysis,
            'pl'        => $pl,
            'error'     => $error,
            'activeNav' => 'dashboard',
            'pageTitle' => 'Dashboard',
        ]);
    }

    public function revenue(): void
    {
        ea_require_login();

        // Period filter: ?period=ytd|mtd|month|yoy&year=YYYY&month=MM
        $period = [
            'type'  => $_GET['period'] ?? 'ytd',
            'year'  => $_GET['year']   ?? null,
            'month' => $_GET['month']  ?? null,
        ];
        $cacheKey = 'revenue_' . md5(json_encode($period));

        $error = null;
        $analysis = null;
        $topCustomers = [];
        $topProducts  = [];

        try {
            $svc = $this->makeService();
            $analysis = ea_cached($cacheKey, fn() => $svc->analyze(null, $period));
            $topCustomers = $svc->topCustomers($analysis, 5);
            $topProducts  = $svc->topProducts($analysis, 5);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('revenue', [
            'analysis'     => $analysis,
            'topCustomers' => $topCustomers,
            'topProducts'  => $topProducts,
            'period'       => $period,
            'error'        => $error,
            'activeNav'    => 'revenue',
            'pageTitle'    => 'Revenue',
        ]);
    }

    public function apiRevenue(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            $analysis = $this->makeService()->analyze();
            unset($analysis['partner_revenue_cy'], $analysis['partner_names_cy'], $analysis['doc_ids_cy']);
            echo json_encode($analysis, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------

    private function makeService(): RevenueService
    {
        $client = OdooFactory::fromConfig();
        $source = (string) Config::get('REVENUE_SOURCE', 'invoice');
        return new RevenueService($client, $source);
    }
}
