<?php
/** TEMP diagnostic — dashboard render check (warm caches). Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $req = Illuminate\Http\Request::create('/t/transatlanticd/', 'GET');   // no fresh=1 → use caches
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $t = microtime(true);
    $html = app(App\Http\Controllers\DashboardController::class)->index($req)->render();
    echo '/dashboard render: ' . round(microtime(true) - $t, 1) . 's · len=' . strlen($html)
       . ' · DSO=' . (strpos($html, '>DSO<') !== false ? 'y' : 'n')
       . ' · DPO=' . (strpos($html, '>DPO<') !== false ? 'y' : 'n')
       . ' · Cost/scan=' . (strpos($html, 'Cost / scan') !== false ? 'y' : 'n')
       . ' · Rev/scan=' . (strpos($html, 'Revenue / scan') !== false ? 'y' : 'n')
       . ' · EBITDA margin=' . (strpos($html, 'EBITDA margin') !== false ? 'y' : 'n')
       . ' · EBIT margin=' . (strpos($html, 'EBIT margin') !== false ? 'y' : 'n')
       . ' · arrows=' . (strpos($html, '▲') !== false || strpos($html, '▼') !== false ? 'y' : 'n') . "\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
