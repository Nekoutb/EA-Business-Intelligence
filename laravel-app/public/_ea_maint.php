<?php
/** TEMP read-only probe — Kissywears inventory audit: GL class-3 composition vs
 *  physical stock valuation. Token-guarded. No writes. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }
function nf($x) { return number_format((float) $x); }
function vv($x) { if ($x === false || $x === null || $x === '') return '—'; if (is_array($x)) return (string) ($x[1] ?? $x[0] ?? '—'); return (string) $x; }

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    $today = new DateTimeImmutable('today'); $todayStr = $today->format('Y-m-d');
    ln("Tenant: {$tenant->name} (slug={$tenant->slug}) — as of {$todayStr}");

    $uid = $client->authenticate();
    $user = $client->read('res.users', [$uid], ['company_id']);
    $cid = is_array($user[0]['company_id'] ?? null) ? (int) $user[0]['company_id'][0] : 1;
    ln("company_id={$cid}");
    ln();

    // ── 1. What InventoryService actually returns ─────────────────────────
    ln('=== 1. InventoryService::analyze (the headline figures) ===');
    $inv = (new App\Services\InventoryService($client))->analyze($today);
    $d = $inv['inventory_days'] ?? [];
    ln('  ending_inventory (today)   = ' . nf($d['ending_inventory'] ?? 0) . ' ' . ($inv['currency'] ?? ''));
    ln('  beginning_inventory (1 Jan)= ' . nf($d['beginning_inventory'] ?? 0));
    ln('  cogs_ytd (class 60)        = ' . nf($d['cogs_ytd'] ?? 0));
    ln('  inventory days outstanding = ' . ($d['days_outstanding'] ?? '—'));
    $tr = $inv['trend']['by_month'] ?? [];
    if ($tr) { $last = end($tr); ln('  trend last point: ' . vv($last['month'] ?? '?') . ' = ' . nf($last['value'] ?? 0)); }
    if (!empty($inv['error'])) ln('  errors: ' . $inv['error']);
    ln();

    // ── 2. GL class-3 (ex 39) composition — what makes up the 10M ─────────
    ln('=== 2. GL class-3 (ex 39) account composition — posted, date<=today ===');
    $accts = $client->searchRead('account.account', [['code', '=like', '3%']], ['id', 'code', 'name'], ['limit' => 1000, 'order' => 'code asc']);
    $accts = array_values(array_filter($accts, fn ($a) => str_starts_with((string) ($a['code'] ?? ''), '3') && !str_starts_with((string) ($a['code'] ?? ''), '39')));
    $byId = [];
    foreach ($accts as $a) $byId[(int) $a['id']] = $a['code'] . ' ' . $a['name'];
    $ids = array_keys($byId);
    $glTotal = 0.0;
    if ($ids) {
        $g = $client->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['company_id', '=', $cid], ['date', '<=', $todayStr]], 'fields' => ['balance:sum'], 'groupby' => ['account_id'], 'lazy' => false]);
        $rows = [];
        foreach ((array) $g as $r) { $aid = is_array($r['account_id']) ? (int) $r['account_id'][0] : 0; $rows[] = [$byId[$aid] ?? vv($r['account_id'] ?? '?'), (float) ($r['balance'] ?? 0), (int) ($r['__count'] ?? 0)]; $glTotal += (float) ($r['balance'] ?? 0); }
        usort($rows, fn ($a, $b) => abs($b[1]) <=> abs($a[1]));
        foreach ($rows as $r) ln(sprintf('  %-44s bal=%14s  lines=%d', substr($r[0], 0, 44), nf($r[1]), $r[2]));
    }
    ln('  GL class-3 (ex 39) TOTAL = ' . nf($glTotal) . '  ← should equal ending_inventory');
    ln();

    // ── 3. Physical stock valuation ──────────────────────────────────────
    ln('=== 3. PHYSICAL stock valuation (to reconcile against GL) ===');
    // 3a. stock.valuation.layer (Enterprise)
    try {
        $svl = $client->callModel('stock.valuation.layer', 'read_group', ['domain' => [['company_id', '=', $cid]], 'fields' => ['value:sum', 'quantity:sum'], 'groupby' => [], 'lazy' => false]);
        ln('  (a) stock.valuation.layer: value=' . nf($svl[0]['value'] ?? 0) . ' qty=' . nf($svl[0]['quantity'] ?? 0) . " (n={$svl[0]['__count']})");
    } catch (\Throwable $e) { ln('  (a) stock.valuation.layer: N/A (' . $e->getMessage() . ')'); }

    // 3b. stock.quant × standard_price (Community)
    try {
        $gq = $client->callModel('stock.quant', 'read_group', ['domain' => [['location_id.usage', '=', 'internal']], 'fields' => ['quantity:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
        $qtyByProd = []; $totQty = 0.0;
        foreach ((array) $gq as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) { $qtyByProd[(int) $p[0]] = (float) ($r['quantity'] ?? 0); $totQty += (float) ($r['quantity'] ?? 0); } }
        $pids = array_keys($qtyByProd);
        $val = 0.0; $priced = 0;
        foreach (array_chunk($pids, 200) as $chunk) {
            $prods = $client->read('product.product', $chunk, ['standard_price']);
            foreach ($prods as $p) { $sp = (float) ($p['standard_price'] ?? 0); $q = $qtyByProd[(int) $p['id']] ?? 0; if ($sp > 0) $priced++; $val += $q * $sp; }
        }
        ln('  (b) stock.quant on-hand (internal): ' . count($pids) . ' products, total qty ' . nf($totQty));
        ln('      valuation (Σ qty × standard_price) = ' . nf($val) . "  ({$priced} products with a cost)");
    } catch (\Throwable $e) { ln('  (b) stock.quant: ERR ' . $e->getMessage()); }
    ln();

    ln('=== RECONCILIATION ===');
    ln('  GL inventory (class 3 ex 39)   = ' . nf($glTotal));
    ln('  → this is what the page shows. Physical valuation above is the cross-check.');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
