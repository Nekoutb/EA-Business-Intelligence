<?php
/** TEMP read-only probe — verify ReportsService daily output. Token-guarded. */
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
    $client = App\Odoo\OdooFactory::fromConfig();
    $svc = new App\Services\ReportsService($client);
    $date = '2026-06-12';

    ln('currency = ' . $svc->currency());
    ln();
    $s = $svc->dailySales($date);
    ln("dailySales({$date}): invoices={$s['invoice_count']} outfits=" . nf($s['outfits']) . ' total=' . nf($s['total']));
    ln('  cash=' . nf($s['cash_total']) . " ({$s['cash_count']})   credit=" . nf($s['credit_total']) . " ({$s['credit_count']})");
    foreach (array_slice($s['rows'], 0, 3) as $r) ln('  • ' . $r['invoice'] . ' | ' . $r['customer'] . ' | ' . nf($r['amount']) . ' | ' . $r['type']);
    ln();
    $i = $svc->dailyInventory($date);
    ln("dailyInventory({$date}):");
    ln('  inward : qty ' . nf($i['inward']['qty']) . ' value@std ' . nf($i['inward']['value']) . " ({$i['inward']['product_count']} products)");
    ln('  outward: qty ' . nf($i['outward']['qty']) . ' value@std ' . nf($i['outward']['value']) . " ({$i['outward']['product_count']} products)");
    ln('  net value = ' . nf($i['net_value']) . '  ·  on-hand@std = ' . nf($i['on_hand_value']));
    ln();
    $a = $svc->monthActivity(2026, 6);
    ln('monthActivity(2026-06): ' . count($a) . ' active days — ' . implode(', ', array_keys($a)));
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
