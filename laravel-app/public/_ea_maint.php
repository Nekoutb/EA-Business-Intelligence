<?php
/** TEMP diagnostic — render treasury (with payables) + forecast YTD/MTD. Token-guarded. Re-stubbed after. */
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
    $req = Illuminate\Http\Request::create('/t/transatlanticd/treasury', 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $cases = [
        ['/treasury',     App\Http\Controllers\TreasuryController::class,   []],
        ['/pl-forecast',  App\Http\Controllers\PLForecastController::class, ['view' => 'ytd', 'year' => 2026]],
        ['/pl-forecast',  App\Http\Controllers\PLForecastController::class, ['view' => 'mtd', 'year' => 2026, 'month' => 5]],
        ['/payables',     App\Http\Controllers\PayablesController::class,   []],
    ];
    foreach ($cases as $c) {
        try {
            $rq = Illuminate\Http\Request::create('/t/transatlanticd' . $c[0], 'GET', $c[2]);
            $rq->setLaravelSession($session); $app->instance('request', $rq);
            $resp = ($c[1] === App\Http\Controllers\PayablesController::class)
                ? app($c[1])->index()
                : app($c[1])->index($rq);
            $html = (is_object($resp) && method_exists($resp, 'render')) ? $resp->render() : '';
            $tag = $c[0] . (isset($c[2]['view']) ? ('?view=' . $c[2]['view'] . (isset($c[2]['month']) ? '&m=' . $c[2]['month'] : '')) : '');
            $hasPay = (strpos((string) $html, 'Trade payables') !== false) ? ' [payables✓]' : '';
            $hasDpo = (strpos((string) $html, 'ec-dpo-trend') !== false) ? ' [dpo-chart✓]' : '';
            echo "RENDER {$tag}: OK len=" . strlen((string) $html) . $hasPay . $hasDpo . "\n";
        } catch (\Throwable $e) {
            echo "RENDER {$c[0]}: FAIL " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
} catch (\Throwable $e) {
    echo 'TOP EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
