<?php
/** TEMP probe — verify on-hand@std now varies by selected date. Token-guarded. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }
function nf($x) { return number_format((float) $x); }

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $svc = new App\Services\ReportsService(App\Odoo\OdooFactory::fromConfig());
    foreach (['2026-05-31', '2026-06-12', '2026-06-20', date('Y-m-d')] as $d) {
        $inv = $svc->dailyInventory($d);
        ln($d . ' : on_hand@std = ' . nf($inv['on_hand_value']) . ' (qty ' . nf($inv['on_hand_qty'] ?? 0) . ')  ·  in ' . nf($inv['inward']['value']) . ' / out ' . nf($inv['outward']['value']));
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
