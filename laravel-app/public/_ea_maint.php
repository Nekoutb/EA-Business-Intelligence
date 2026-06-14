<?php
/** TEMP diagnostic — isolate buildExtraKpis cost + values. Token-guarded. Re-stubbed after. */
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
    $client = App\Odoo\OdooFactory::fromConfig();

    // Time a couple of single Odoo calls to gauge raw latency.
    $t = microtime(true);
    try { $client->searchCount('aw_cargo.equipment', [['scanned', '=', true], ['scanned_date', '>=', '2026-05-01 00:00:00'], ['scanned_date', '<=', '2026-05-31 23:59:59']]); } catch (\Throwable $e) {}
    echo 'one searchCount: ' . round(microtime(true) - $t, 2) . "s\n";

    $ctrl = app(App\Http\Controllers\DashboardController::class);
    $m = new ReflectionMethod($ctrl, 'buildExtraKpis'); $m->setAccessible(true);
    $t = microtime(true);
    $kpis = $m->invoke($ctrl, $client, 2026);
    echo 'buildExtraKpis: ' . round(microtime(true) - $t, 1) . "s · " . count($kpis) . " KPIs\n";
    foreach ($kpis as $k) {
        $v = $k['value']; $p = $k['prev'];
        $better = (is_numeric($v) && is_numeric($p)) ? (($v == $p) ? 'flat' : (($k['higher_better'] ? $v > $p : $v < $p) ? 'BETTER' : 'WORSE')) : 'n/a';
        echo '  ' . str_pad($k['label'], 15) . ' val=' . json_encode(is_numeric($v) ? round((float) $v, 1) : $v) . ' prev=' . json_encode(is_numeric($p) ? round((float) $p, 1) : $p) . ' -> ' . $better . "\n";
    }
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
