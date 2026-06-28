<?php
/** TEMP diagnostic — render /inventory?view=reports (Option B) for Kissywears. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $req = Illuminate\Http\Request::create('/t/' . $tenant->slug . '/inventory', 'GET', ['view' => 'reports', 'date' => '2026-06-12', 'month' => 6, 'year' => 2026]);
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $t = microtime(true);
    $html = app(App\Http\Controllers\InventoryController::class)->index($req)->render();
    echo 'render ' . round(microtime(true) - $t, 1) . 's · len=' . strlen($html) . "\n";
    echo '  calendar (Select a day) = ' . (strpos($html, 'Select a day') !== false ? 'y' : 'n') . "\n";
    echo '  sales sandbox           = ' . (strpos($html, 'Sales ·') !== false ? 'y' : 'n') . "\n";
    echo '  cash/credit KPIs        = ' . (strpos($html, 'Cash sales') !== false && strpos($html, 'Credit sales') !== false ? 'y' : 'n') . "\n";
    echo '  outfits KPI             = ' . (strpos($html, 'Outfits sold') !== false ? 'y' : 'n') . "\n";
    echo '  inventory sandbox       = ' . (strpos($html, 'Inventory movements ·') !== false ? 'y' : 'n') . "\n";
    echo '  std-cost value          = ' . (strpos($html, 'On-hand @ std') !== false ? 'y' : 'n') . "\n";
    echo '  customer rows           = ' . (strpos($html, 'Leukouo') !== false || strpos($html, 'SANDRINE') !== false ? 'y' : 'n') . "\n";
    echo '  inventory-page leakage  = ' . (strpos($html, 'Inventory Days') !== false || strpos($html, 'ec-inv') !== false ? 'LEAK' : 'clean') . "\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
