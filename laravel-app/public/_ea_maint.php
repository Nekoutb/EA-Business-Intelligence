<?php
/** TEMP diagnostic — verify monthly per-scan + render. Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $year   = (int) ($_GET['year'] ?? 2026);
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);

    $req = Illuminate\Http\Request::create('/t/transatlanticd/profit-loss', 'GET', ['view' => 'profitability', 'year' => $year]);
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $client = App\Odoo\OdooFactory::fromConfig();
    $assum  = app(App\Services\BudgetService::class)->loadAssumptions($tenant->id, $year);
    $d = (new App\Services\ProfitabilityService($client))->analyze($year, $assum);

    $rnd = fn ($a) => json_encode(array_map(fn ($v) => $v === null ? null : (int) round($v), $a));
    echo "year $year · thru_month {$d['trend']['thru_month']}\n";
    echo 'monthly scans:   ' . json_encode($d['monthly_per_scan']['scans']) . "\n";
    echo 'revenue / scan:  ' . $rnd($d['monthly_per_scan']['revenue']) . "\n";
    echo 'cost / scan:     ' . $rnd($d['monthly_per_scan']['cost']) . "\n";
    echo 'trend revenue(M): ' . json_encode(array_map(fn ($v) => round(((float) $v) / 1e6, 1), $d['trend']['revenue'])) . "\n";

    // Render check (uses the v2 cache key).
    $resp = app(App\Http\Controllers\ProfitLossController::class)->index($req);
    $html = (is_object($resp) && method_exists($resp, 'render')) ? $resp->render() : '';
    echo 'RENDER: OK len=' . strlen((string) $html) . "\n";
} catch (\Throwable $e) {
    echo 'EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
