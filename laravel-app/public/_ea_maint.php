<?php
/** TEMP read-only probe — Kissywears daily-report feasibility: sales-by-day
 *  (cash vs credit signals) + inventory movements at standard cost. No writes. */
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
    $uid = $client->authenticate();
    $cid = (int) ($client->read('res.users', [$uid], ['company_id'])[0]['company_id'][0] ?? 1);

    // ── Find an active sales day in June ──────────────────────────────────
    ln('=== A. Customer invoices per day (June 2026) ===');
    $g = $client->callModel('account.move', 'read_group', ['domain' => [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'], ['invoice_date', '>=', '2026-06-01'], ['invoice_date', '<=', '2026-06-30']], 'fields' => ['amount_total_signed:sum'], 'groupby' => ['invoice_date:day'], 'lazy' => false]);
    $busiest = null; $maxN = 0;
    foreach ((array) $g as $r) {
        $n = (int) ($r['__count'] ?? 0);
        $day = null;
        foreach (['invoice_date', 'invoice_date:day'] as $k) if (isset($r['__range'][$k]['from'])) { $day = substr($r['__range'][$k]['from'], 0, 10); break; }
        ln("  {$day}: {$n} invoices, " . nf($r['amount_total_signed'] ?? 0));
        if ($n > $maxN) { $maxN = $n; $busiest = $day; }
    }
    ln("  → busiest day: {$busiest}");
    ln();

    if ($busiest) {
        $d0 = $busiest; $d1 = $busiest;
        // ── Sales detail for that day ─────────────────────────────────────
        ln("=== B. Sales detail for {$busiest} ===");
        $invs = $client->searchRead('account.move', [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'], ['invoice_date', '=', $d0]], ['partner_id', 'amount_total_signed', 'amount_untaxed_signed', 'payment_state', 'journal_id'], ['limit' => 200]);
        $tot = 0; $custs = []; $byPay = []; $byJrnl = [];
        foreach ($invs as $i) {
            $tot += (float) ($i['amount_total_signed'] ?? 0);
            $custs[vv($i['partner_id'] ?? '?')] = ($custs[vv($i['partner_id'] ?? '?')] ?? 0) + (float) ($i['amount_total_signed'] ?? 0);
            $ps = vv($i['payment_state'] ?? '?'); $byPay[$ps] = ($byPay[$ps] ?? 0) + (float) ($i['amount_total_signed'] ?? 0);
            $jr = vv($i['journal_id'] ?? '?'); $byJrnl[$jr] = ($byJrnl[$jr] ?? 0) + (float) ($i['amount_total_signed'] ?? 0);
        }
        ln('  invoices=' . count($invs) . ' total=' . nf($tot) . ' customers=' . count($custs));
        // qty of outfits sold
        $ids = array_column($invs, 'id');
        if ($ids) {
            $qg = $client->callModel('account.move.line', 'read_group', ['domain' => [['move_id', 'in', $ids], ['display_type', '=', 'product']], 'fields' => ['quantity:sum'], 'groupby' => [], 'lazy' => false]);
            ln('  outfits (Σ line qty) = ' . nf($qg[0]['quantity'] ?? 0) . ' over ' . (int) ($qg[0]['__count'] ?? 0) . ' lines');
        }
        ln('  by payment_state: ' . json_encode(array_map('round', $byPay)));
        ln('  by journal: ' . json_encode(array_map('round', $byJrnl)));
        ln('  customers: ' . implode(', ', array_slice(array_keys($custs), 0, 8)) . (count($custs) > 8 ? ' …' : ''));
        ln();
    }

    // ── POS presence (cash sales often = POS) ─────────────────────────────
    ln('=== C. Point of Sale ===');
    try {
        $pn = $client->searchCount('pos.order', [['date_order', '>=', '2026-06-01']]);
        ln("  pos.order exists — June count = {$pn}");
    } catch (\Throwable $e) { ln('  pos.order: N/A (' . $e->getMessage() . ')'); }
    ln();

    // ── Inventory movements (stock.move) for the busiest sales day ────────
    ln("=== D. Inventory movements for {$busiest} (at standard cost) ===");
    $dayDom = fn ($extra) => array_merge([['state', '=', 'done'], ['date', '>=', $busiest . ' 00:00:00'], ['date', '<=', $busiest . ' 23:59:59']], $extra);
    foreach (['INWARD' => [['location_dest_id.usage', '=', 'internal'], ['location_id.usage', '!=', 'internal']], 'OUTWARD' => [['location_id.usage', '=', 'internal'], ['location_dest_id.usage', '!=', 'internal']]] as $dir => $extra) {
        try {
            $mg = $client->callModel('stock.move', 'read_group', ['domain' => $dayDom($extra), 'fields' => ['product_qty:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
            $qtyByProd = []; $totQty = 0;
            foreach ((array) $mg as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) { $qtyByProd[(int) $p[0]] = (float) ($r['product_qty'] ?? 0); $totQty += (float) ($r['product_qty'] ?? 0); } }
            $val = 0;
            if ($qtyByProd) { $prods = $client->read('product.product', array_keys($qtyByProd), ['standard_price']); foreach ($prods as $p) $val += ($qtyByProd[(int) $p['id']] ?? 0) * (float) ($p['standard_price'] ?? 0); }
            ln("  {$dir}: " . count($qtyByProd) . ' products, qty ' . nf($totQty) . ', value@std ' . nf($val));
        } catch (\Throwable $e) { ln("  {$dir}: ERR " . $e->getMessage()); }
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
