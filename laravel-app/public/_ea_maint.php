<?php
/** TEMP diagnostic — payables fix + treasury cleanup. Token-guarded. Re-stubbed after. */
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
    $req = Illuminate\Http\Request::create('/t/transatlanticd/payables', 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    // Render PAYABLES and confirm the KPI figures are no longer zero.
    try {
        $resp = app(App\Http\Controllers\PayablesController::class)->index();
        $html = $resp->render();
        $zeros = substr_count($html, '0 XAF');
        echo '/payables: OK len=' . strlen($html)
           . ' · Total-AP-card shows ' . (strpos($html, '>0 XAF<') !== false ? 'ZERO(!)' : 'a value')
           . ' · top30-rows=' . substr_count($html, 'hover:bg-slate-50')
           . ' · dpo-chart=' . (strpos($html, 'apDpoTrend') !== false ? 'yes' : 'no')
           . ' · aging=' . (strpos($html, '>Aging<') !== false ? 'yes' : 'no') . "\n";
    } catch (\Throwable $e) { echo '/payables: FAIL ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }

    // Render TREASURY and confirm the payables section is gone.
    try {
        $rq = Illuminate\Http\Request::create('/t/transatlanticd/treasury', 'GET');
        $rq->setLaravelSession($session); $app->instance('request', $rq);
        $html = app(App\Http\Controllers\TreasuryController::class)->index($rq)->render();
        echo '/treasury: OK len=' . strlen($html)
           . ' · Trade-payables present=' . (strpos($html, 'Trade payables') !== false ? 'YES(!)' : 'no (removed ✓)')
           . ' · liq-chart=' . (strpos($html, 'ec-liq-trend') !== false ? 'yes' : 'no')
           . ' · ccc-chart=' . (strpos($html, 'ec-ccc-trend') !== false ? 'yes' : 'no') . "\n";
    } catch (\Throwable $e) { echo '/treasury: FAIL ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
} catch (\Throwable $e) {
    echo 'TOP EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
