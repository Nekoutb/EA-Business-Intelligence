<?php
/** TEMP diagnostic — running cumulative DPO vs headline. Token-guarded. Re-stubbed after. */
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

    $c   = App\Odoo\OdooFactory::fromConfig();
    $pl  = (new App\Services\ProfitLossService($c))->analyze();
    $exp = max(1.0, (float)($pl['current']['cogs']??0) + (float)($pl['current']['opex']??0) + (float)($pl['current']['d_and_a']??0));
    $svc = new App\Services\PayablesService($c);
    $ap  = $svc->analyze($exp, 365, 'XAF');
    echo 'headline dpo_days: ' . round((float)($ap['kpis']['dpo_days'] ?? 0), 1) . "\n";
    echo 'total_ap(M): ' . round((float)($ap['kpis']['total_ap'] ?? 0)/1e6, 1) . ' · ytd_exp(M): ' . round($exp/1e6, 1) . "\n";
    echo "running DPO series:\n";
    foreach ($svc->dpoRunningSeries((float)($ap['kpis']['total_ap'] ?? 0), $exp, 365) as $p) {
        echo '  ' . str_pad($p['label'] ?? '?', 8) . ' DPO=' . json_encode($p['dpo']) . "\n";
    }

    try {
        $resp = app(App\Http\Controllers\PayablesController::class)->index();
        echo '/payables render: OK len=' . strlen($resp->render()) . "\n";
    } catch (\Throwable $e) { echo '/payables render: FAIL ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
} catch (\Throwable $e) {
    echo 'TOP EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
