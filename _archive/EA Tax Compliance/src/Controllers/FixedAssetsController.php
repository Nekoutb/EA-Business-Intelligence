<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\FixedAssetsService;

final class FixedAssetsController
{
    public function index(): void
    {
        ea_require_login();

        $error = null;
        $fa    = null;

        try {
            $fa = ea_cached('fa', fn() => (new FixedAssetsService(OdooFactory::fromConfig()))->analyze());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        ea_render('fixed_assets', [
            'fa'        => $fa,
            'error'     => $error,
            'activeNav' => 'fixed_assets',
            'pageTitle' => 'Fixed Assets',
        ]);
    }

    public function apiFixedAssets(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            echo json_encode((new FixedAssetsService(OdooFactory::fromConfig()))->analyze(), JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
