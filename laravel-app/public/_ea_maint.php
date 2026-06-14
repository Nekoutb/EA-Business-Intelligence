<?php
/** TEMP diagnostic — dashboard render + efficiency KPI strip + timing. Token-guarded. Re-stubbed after. */
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
    $req = Illuminate\Http\Request::create('/t/transatlanticd/', 'GET', ['fresh' => 1]);
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    // Time just the extra-KPI builder (the new work).
    $client = App\Odoo\OdooFactory::fromConfig();
    $ctrl   = app(App\Http\Controllers\DashboardController::class);
    $m = new ReflectionMethod($ctrl, 'buildExtraKpis'); $m->setAccessible(true);
    $t0 = microtime(true);
    $kpis = $m->invoke($ctrl, $client, 2026);
    $dt = round(microtime(true) - $t0, 1);
    echo "buildExtraKpis: {$dt}s · " . count($kpis) . " KPIs\n";
    foreach ($kpis as $key => $k) {
        $v = $k['value']; $p = $k['prev'];
        $better = (is_numeric($v) && is_numeric($p)) ? (($v == $p) ? 'flat' : (($k['higher_better'] ? $v > $p : $v < $p) ? 'BETTER' : 'WORSE')) : 'n/a';
        echo '  ' . str_pad($k['label'], 15) . ' val=' . json_encode(is_numeric($v) ? round((float)$v, 1) : $v) . ' prev=' . json_encode(is_numeric($p) ? round((float)$p, 1) : $p) . ' -> ' . $better . "\n";
    }

    $t1 = microtime(true);
    $html = app(App\Http\Controllers\DashboardController::class)->index($req)->render();
    echo "\n/dashboard render: " . round(microtime(true) - $t1, 1) . "s · len=" . strlen($html)
       . " · DSO=" . (strpos($html, '>DSO<') !== false ? 'y' : 'n')
       . " · arrows=" . (strpos($html, '▲') !== false || strpos($html, '▼') !== false ? 'y' : 'n') . "\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
