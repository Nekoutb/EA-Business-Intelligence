<?php
/** TEMP read-only probe — Kissywears Odoo accounting config: anglo-saxon flag +
 *  product category valuation settings. Token-guarded. No writes. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }
function vv($x) { if ($x === false || $x === null || $x === '') return '—'; if (is_array($x)) return (string) ($x[1] ?? $x[0] ?? '—'); return (string) $x; }

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    $uid = $client->authenticate();
    $user = $client->read('res.users', [$uid], ['company_id']);
    $cid = is_array($user[0]['company_id'] ?? null) ? (int) $user[0]['company_id'][0] : 1;

    ln('=== COMPANY ===');
    $co = $client->read('res.company', [$cid], ['name', 'anglo_saxon_accounting', 'currency_id', 'account_sale_tax_id']);
    ln('  name = ' . vv($co[0]['name'] ?? '?'));
    ln('  anglo_saxon_accounting = ' . json_encode($co[0]['anglo_saxon_accounting'] ?? null) . '   ← TRUE = Anglo-Saxon, FALSE = Continental(OHADA)');
    ln('  currency = ' . vv($co[0]['currency_id'] ?? '?'));
    ln();

    ln('=== PRODUCT CATEGORIES — valuation config ===');
    $fields = ['name', 'property_valuation', 'property_cost_method',
        'property_stock_valuation_account_id', 'property_stock_account_input_categ_id',
        'property_stock_account_output_categ_id', 'property_account_expense_categ_id',
        'property_account_income_categ_id', 'property_stock_journal'];
    // price difference field name varies by version — try to include it, fall back if it errors
    $cats = [];
    try {
        $cats = $client->searchRead('product.category', [], array_merge($fields, ['property_account_creditor_price_difference_categ']), ['limit' => 50, 'order' => 'name']);
    } catch (\Throwable $e) {
        $cats = $client->searchRead('product.category', [], $fields, ['limit' => 50, 'order' => 'name']);
    }
    foreach ($cats as $c) {
        ln('  • ' . vv($c['name'] ?? '?'));
        ln('      valuation    = ' . vv($c['property_valuation'] ?? '?') . '   (manual_periodic = Periodic, real_time = Perpetual)');
        ln('      cost_method  = ' . vv($c['property_cost_method'] ?? '?'));
        ln('      stock acct   = ' . vv($c['property_stock_valuation_account_id'] ?? false));
        ln('      input acct   = ' . vv($c['property_stock_account_input_categ_id'] ?? false));
        ln('      output acct  = ' . vv($c['property_stock_account_output_categ_id'] ?? false));
        ln('      expense acct = ' . vv($c['property_account_expense_categ_id'] ?? false));
        ln('      income acct  = ' . vv($c['property_account_income_categ_id'] ?? false));
        ln('      price diff   = ' . vv($c['property_account_creditor_price_difference_categ'] ?? false));
        ln('      stock journal= ' . vv($c['property_stock_journal'] ?? false));
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
