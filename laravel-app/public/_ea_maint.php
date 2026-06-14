<?php
/** TEMP diagnostic — KPI trends: scansByMonth, buildExtraKpis series, budget
 *  round-trip, dashboard render. Token-guarded. Re-stubbed after. */
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

    $mS = new ReflectionMethod($ctrl, 'scansByMonth'); $mS->setAccessible(true);
    $t = microtime(true); $scans = $mS->invoke($ctrl, $client, 2026);
    echo 'scansByMonth (' . round(microtime(true) - $t, 1) . 's): ' . json_encode($scans) . "\n";

    $mB = new ReflectionMethod($ctrl, 'buildExtraKpis'); $mB->setAccessible(true);
    $t = microtime(true); $k = $mB->invoke($ctrl, $client, 2026);
    echo 'buildExtraKpis (' . round(microtime(true) - $t, 1) . 's): ' . count($k) . " KPIs\n";
    foreach ($k as $v) {
        $s = $v['series'] ?? [];
        $nn = count(array_filter($s, fn ($x) => $x !== null));
        echo '  ' . str_pad($v['label'], 15) . ' val=' . json_encode(is_numeric($v['value']) ? round((float) $v['value'], 1) : $v['value'])
           . ' prev=' . json_encode(is_numeric($v['prev']) ? round((float) $v['prev'], 1) : $v['prev'])
           . ' series_nonnull=' . $nn . "\n";
    }
    $rs = $k['rev_per_scan']['series'] ?? [];
    echo 'rev_per_scan series=' . json_encode(array_map(fn ($x) => $x === null ? null : round((float) $x, 0), $rs)) . " (May idx4 expect ~14941)\n";

    // KPI budget round-trip (save test, read back, restore original).
    $bs = app(App\Services\BudgetService::class);
    $orig = $bs->loadKpiBudgets($tenant->id, 2026);
    $bs->saveKpiBudgets($tenant, 2026, ['rev_per_scan' => 15000, 'dpo' => 30], $u?->id);
    echo 'budget round-trip: after-save=' . json_encode($bs->loadKpiBudgets($tenant->id, 2026)) . "\n";
    $bs->saveKpiBudgets($tenant, 2026, array_filter($orig, fn ($x) => $x !== null), $u?->id);
    echo 'budget restored=' . json_encode($bs->loadKpiBudgets($tenant->id, 2026)) . "\n";

    $t = microtime(true);
    $html = app(App\Http\Controllers\DashboardController::class)->index($req)->render();
    echo 'render (' . round(microtime(true) - $t, 1) . 's) len=' . strlen($html)
       . ' panel=' . (strpos($html, 'KPI Targets') !== false ? 'y' : 'n')
       . ' form=' . (strpos($html, 'value="kpi_budget"') !== false ? 'y' : 'n')
       . ' charts=' . substr_count($html, 'class="kchart"')
       . ' chartjson=' . (strpos($html, 'kg-rev_per_scan') !== false ? 'y' : 'n') . "\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
