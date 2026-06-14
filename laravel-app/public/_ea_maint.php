<?php
/** TEMP diagnostic — dashboard efficiency KPIs. Token-guarded. Re-stubbed after. */
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
    $req = Illuminate\Http\Request::create('/t/transatlanticd/', 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $client = App\Odoo\OdooFactory::fromConfig();
    $ctrl   = app(App\Http\Controllers\DashboardController::class);
    $m = new ReflectionMethod($ctrl, 'buildExtraKpis');
    $m->setAccessible(true);
    $kpis = $m->invoke($ctrl, $client, 2026);

    echo "=== EXTRA KPIS (" . count($kpis) . ") ===\n";
    foreach ($kpis as $key => $k) {
        $v = $k['value']; $p = $k['prev']; $hb = $k['higher_better'] ? 'higher=better' : 'lower=better';
        $better = (is_numeric($v) && is_numeric($p)) ? (($v == $p) ? 'flat' : (($k['higher_better'] ? $v > $p : $v < $p) ? 'BETTER' : 'WORSE')) : 'n/a';
        echo '  ' . str_pad($k['label'], 16) . ' value=' . json_encode(is_numeric($v) ? round((float)$v, 1) : $v)
           . ' prev=' . json_encode(is_numeric($p) ? round((float)$p, 1) : $p)
           . ' [' . $hb . '] -> ' . $better . "\n";
    }

    try {
        $rq = Illuminate\Http\Request::create('/t/transatlanticd/', 'GET');
        $rq->setLaravelSession($session); $app->instance('request', $rq);
        $html = app(App\Http\Controllers\DashboardController::class)->index($rq)->render();
        echo "\n/dashboard render: OK len=" . strlen($html)
           . ' · DSO=' . (strpos($html, '>DSO<') !== false ? 'y' : 'n')
           . ' · DPO=' . (strpos($html, '>DPO<') !== false ? 'y' : 'n')
           . ' · Cost/scan=' . (strpos($html, 'Cost / scan') !== false ? 'y' : 'n')
           . ' · EBITDA-margin=' . (strpos($html, 'EBITDA margin') !== false ? 'y' : 'n')
           . ' · arrows=' . (strpos($html, '▲') !== false || strpos($html, '▼') !== false ? 'y' : 'n') . "\n";
    } catch (\Throwable $e) { echo "/dashboard render: FAIL " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
} catch (\Throwable $e) {
    echo 'TOP EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
