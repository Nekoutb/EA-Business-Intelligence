<?php
/** TEMP diagnostic — procurement dashboard: verify footer removed + hover hints
 *  present. Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    if (function_exists('ea_cache_clear')) ea_cache_clear();

    $req = Illuminate\Http\Request::create('/t/transatlanticd/procurement', 'GET', ['view' => 'dashboard']);
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::where('tenant_id', $tenant->id)->whereIn('role', ['tenant_admin', 'owner'])->first() ?? App\Models\User::first();
    if ($u) Illuminate\Support\Facades\Auth::login($u);

    $t0 = microtime(true);
    $html = app(App\Http\Controllers\ProcurementController::class)->index($req)->render();
    ln('render ' . round(microtime(true) - $t0, 1) . 's · len=' . strlen($html));
    ln('  tiles                : ' . substr_count($html, 'pd-tile"') + substr_count($html, 'pd-tile" '));
    ln('  tiles w/ title attr  : ' . substr_count($html, '<div class="pd-tile" title="'));
    ln('  footer removed       : ' . (strpos($html, 'Basis.') === false && strpos($html, 'pd-foot') === false ? 'yes' : 'NO — still present'));
    ln('  hint text present    : ' . (strpos($html, 'maverick spend') !== false && strpos($html, 'Days Payable Outstanding') !== false ? 'yes' : 'no'));
    ln('  matrix hints         : ' . (substr_count($html, '<td title="') >= 8 ? 'yes' : 'no (' . substr_count($html, '<td title="') . ')'));
    ln('  admin panel          : ' . (strpos($html, 'Set targets (admin)') !== false ? 'y' : 'n'));
    ln('  print button         : ' . (strpos($html, 'Print / Export PDF') !== false ? 'y' : 'n'));
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
