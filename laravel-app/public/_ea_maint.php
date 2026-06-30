<?php
/** TEMP read-only probe — Kissywears on-hand inventory over time + June moves. */
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

    // Current on-hand quantity per product (internal locations) + std cost.
    $gq = $client->callModel('stock.quant', 'read_group', ['domain' => [['location_id.usage', '=', 'internal']], 'fields' => ['quantity:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
    $qtyNow = []; foreach ((array) $gq as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) $qtyNow[(int) $p[0]] = (float) ($r['quantity'] ?? 0); }
    $std = [];
    foreach (array_chunk(array_keys($qtyNow), 300) as $chunk) { foreach ($client->read('product.product', $chunk, ['standard_price']) as $p) $std[(int) $p['id']] = (float) ($p['standard_price'] ?? 0); }
    $valNow = 0.0; foreach ($qtyNow as $pid => $q) $valNow += $q * ($std[$pid] ?? 0);
    ln('CURRENT on-hand: ' . count($qtyNow) . ' products, qty ' . nf(array_sum($qtyNow)) . ', value@std ' . nf($valNow));
    ln();

    // Net internal moves (qty) per product AFTER a cutoff date.
    $movesAfter = function (string $cutoff) use ($client): array {
        $net = [];
        $grab = function ($extra, $sign) use ($client, $cutoff, &$net) {
            $g = $client->callModel('stock.move', 'read_group', ['domain' => array_merge([['state', '=', 'done'], ['date', '>', $cutoff . ' 23:59:59']], $extra), 'fields' => ['product_qty:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
            foreach ((array) $g as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) $net[(int) $p[0]] = ($net[(int) $p[0]] ?? 0) + $sign * (float) ($r['product_qty'] ?? 0); }
        };
        $grab([['location_dest_id.usage', '=', 'internal'], ['location_id.usage', '!=', 'internal']], +1);  // inward
        $grab([['location_id.usage', '=', 'internal'], ['location_dest_id.usage', '!=', 'internal']], -1);  // outward
        return $net;
    };

    ln('=== Reconstructed on-hand value@std AS OF date (qtyNow − net moves after date) ===');
    foreach (['2026-04-30', '2026-05-31', '2026-06-12', '2026-06-20', date('Y-m-d')] as $C) {
        $net = $movesAfter($C);
        $v = 0.0; foreach ($qtyNow as $pid => $q) { $v += ($q - ($net[$pid] ?? 0)) * ($std[$pid] ?? 0); }
        ln('  as of ' . $C . ' : value@std ' . nf($v));
    }
    ln();

    // June movement totals at std.
    ln('=== Movements (value@std) — June 2026 ===');
    $sum = function ($extra) use ($client, $std) {
        $g = $client->callModel('stock.move', 'read_group', ['domain' => array_merge([['state', '=', 'done'], ['date', '>=', '2026-06-01 00:00:00'], ['date', '<=', '2026-06-30 23:59:59']], $extra), 'fields' => ['product_qty:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
        $q = 0; $v = 0; foreach ((array) $g as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) { $qq = (float) ($r['product_qty'] ?? 0); $q += $qq; $v += $qq * ($std[(int) $p[0]] ?? 0); } }
        return [$q, $v];
    };
    [$iq, $iv] = $sum([['location_dest_id.usage', '=', 'internal'], ['location_id.usage', '!=', 'internal']]);
    [$oq, $ov] = $sum([['location_id.usage', '=', 'internal'], ['location_dest_id.usage', '!=', 'internal']]);
    ln('  inward : qty ' . nf($iq) . ' value@std ' . nf($iv));
    ln('  outward: qty ' . nf($oq) . ' value@std ' . nf($ov));
    ln('  net    : qty ' . nf($iq - $oq) . ' value@std ' . nf($iv - $ov));
    ln();
    // How many done moves total in June (sanity: are moves being created at all)
    $mc = $client->searchCount('stock.move', [['state', '=', 'done'], ['date', '>=', '2026-06-01 00:00:00'], ['date', '<=', '2026-06-30 23:59:59']]);
    ln('  total done stock.moves in June: ' . $mc);
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
