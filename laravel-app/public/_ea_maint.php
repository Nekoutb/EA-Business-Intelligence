<?php
/** TEMP read-only probe — full ReportsService output for a sample day (for the
 *  daily-email mockup). Token-guarded. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $svc = new App\Services\ReportsService(App\Odoo\OdooFactory::fromConfig());
    $date = $_GET['d'] ?? '2026-06-13';

    $s = $svc->dailySales($date);
    $i = $svc->dailyInventory($date);
    echo "TENANT: {$tenant->name}\nDATE: {$date}\n\n";
    echo "SALES: invoices={$s['invoice_count']} outfits={$s['outfits']} total={$s['total']} | cash={$s['cash_total']}({$s['cash_count']}) credit={$s['credit_total']}({$s['credit_count']})\n";
    echo "ROWS:\n";
    foreach ($s['rows'] as $r) echo "  {$r['invoice']} | {$r['customer']} | amt={$r['amount']} | open={$r['open']} | {$r['type']}\n";
    echo "\nINVENTORY: inward qty={$i['inward']['qty']} val={$i['inward']['value']} | outward qty={$i['outward']['qty']} val={$i['outward']['value']} | net_val={$i['net_value']} | on_hand={$i['on_hand_value']}\n";
    echo "INWARD LINES:\n";
    foreach ($i['inward']['lines'] as $l) echo "  {$l['name']} | qty={$l['qty']} | cost={$l['cost']} | value={$l['value']}\n";
    echo "OUTWARD LINES:\n";
    foreach ($i['outward']['lines'] as $l) echo "  {$l['name']} | qty={$l['qty']} | cost={$l['cost']} | value={$l['value']}\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
