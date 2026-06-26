<?php
/** TEMP read-only probe — Kissywears: (1) cash collected under the new
 *  definition, (2) customer profitability feasibility (revenue + cost data).
 *  Token-guarded. No writes. */
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
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $yFrom = '2026-01-01'; $mFrom = '2026-06-01';
    ln("Tenant: {$tenant->name} (slug={$tenant->slug}) — today {$today}");
    ln();

    // ===== PART 1 — CASH COLLECTED =====
    ln('=== 1. CASH COLLECTED (customer invoices) ===');
    // (a) paid amount on paid/partial invoices, by invoice_date window
    $collected = function ($from) use ($client, $today) {
        $dom = [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'], ['payment_state', 'in', ['paid', 'partial', 'in_payment']], ['invoice_date', '>=', $from], ['invoice_date', '<=', $today]];
        $g = $client->callModel('account.move', 'read_group', ['domain' => $dom, 'fields' => ['amount_total_signed:sum', 'amount_residual_signed:sum'], 'groupby' => [], 'lazy' => false]);
        $tot = (float) ($g[0]['amount_total_signed'] ?? 0); $res = (float) ($g[0]['amount_residual_signed'] ?? 0);
        return ['paid' => $tot - $res, 'count' => (int) ($g[0]['__count'] ?? 0), 'total' => $tot];
    };
    $y = $collected($yFrom); $m = $collected($mFrom);
    ln('  (a) Paid amount on PAID/PARTIAL invoices, by invoice_date:');
    ln('       YTD: ' . nf($y['paid']) . "  ({$y['count']} invoices, billed " . nf($y['total']) . ')');
    ln('       MTD: ' . nf($m['paid']) . "  ({$m['count']} invoices, billed " . nf($m['total']) . ')');

    // (b) inbound customer payments posted, by payment date
    $pay = function ($from) use ($client, $today) {
        try {
            $dom = [['payment_type', '=', 'inbound'], ['partner_type', '=', 'customer'], ['state', '=', 'posted'], ['date', '>=', $from], ['date', '<=', $today]];
            $g = $client->callModel('account.payment', 'read_group', ['domain' => $dom, 'fields' => ['amount:sum'], 'groupby' => [], 'lazy' => false]);
            return ['amt' => (float) ($g[0]['amount'] ?? 0), 'count' => (int) ($g[0]['__count'] ?? 0)];
        } catch (\Throwable $e) { return ['amt' => 'ERR ' . $e->getMessage(), 'count' => 0]; }
    };
    $yp = $pay($yFrom); $mp = $pay($mFrom);
    ln('  (b) Inbound customer payments posted, by payment date:');
    ln('       YTD: ' . (is_numeric($yp['amt']) ? nf($yp['amt']) : $yp['amt']) . "  ({$yp['count']} payments)");
    ln('       MTD: ' . (is_numeric($mp['amt']) ? nf($mp['amt']) : $mp['amt']) . "  ({$mp['count']} payments)");

    // (c) payment_state distribution (all out_invoice, posted)
    ln('  (c) Invoice payment_state mix (posted out_invoice, all-time):');
    $gs = $client->callModel('account.move', 'read_group', ['domain' => [['move_type', '=', 'out_invoice'], ['state', '=', 'posted']], 'fields' => ['amount_total_signed:sum', 'amount_residual_signed:sum'], 'groupby' => ['payment_state'], 'lazy' => false]);
    foreach ((array) $gs as $r) ln('       ' . str_pad(vv($r['payment_state'] ?? '?'), 12) . " n=" . (int) ($r['__count'] ?? 0) . ' billed=' . nf($r['amount_total_signed'] ?? 0) . ' open=' . nf($r['amount_residual_signed'] ?? 0));
    ln();

    // ===== PART 2 — CUSTOMER PROFITABILITY FEASIBILITY =====
    ln('=== 2. PROFITABILITY FEASIBILITY (top customers · articles · cost) ===');
    $lineDom = [['move_id.move_type', '=', 'out_invoice'], ['parent_state', '=', 'posted'], ['display_type', '=', 'product'], ['date', '>=', $yFrom], ['date', '<=', $today]];
    // Top customers by invoice-line revenue
    $gc = $client->callModel('account.move.line', 'read_group', ['domain' => $lineDom, 'fields' => ['price_subtotal:sum', 'quantity:sum'], 'groupby' => ['partner_id'], 'lazy' => false]);
    $rows = [];
    foreach ((array) $gc as $r) $rows[] = [vv($r['partner_id'] ?? '?'), (float) ($r['price_subtotal'] ?? 0), (int) ($r['__count'] ?? 0)];
    usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
    ln('  Top 8 customers by line revenue (YTD): ' . count($rows) . ' customers total');
    foreach (array_slice($rows, 0, 8) as $r) ln('    ' . str_pad($r[0], 26) . ' rev=' . nf($r[1]) . " lines={$r[2]}");

    // Distinct products + cost availability
    $gp = $client->callModel('account.move.line', 'read_group', ['domain' => $lineDom, 'fields' => ['price_subtotal:sum'], 'groupby' => ['product_id'], 'lazy' => false]);
    $prodIds = [];
    foreach ((array) $gp as $r) { $p = $r['product_id'] ?? false; if (is_array($p)) $prodIds[] = (int) $p[0]; }
    ln('  Distinct products sold (YTD): ' . count($gp));
    // standard_price (cost) coverage on a sample of those products
    $sample = array_slice($prodIds, 0, 30);
    $withCost = 0; $costSample = [];
    if ($sample) {
        $prods = $client->read('product.product', $sample, ['name', 'standard_price', 'list_price']);
        foreach ($prods as $p) { $sp = (float) ($p['standard_price'] ?? 0); if ($sp > 0) $withCost++; if (count($costSample) < 5) $costSample[] = vv($p['name'] ?? '?') . ' cost=' . nf($sp) . ' list=' . nf($p['list_price'] ?? 0); }
        ln('  product.product.standard_price (cost) populated: ' . $withCost . ' of ' . count($prods) . ' sampled');
        foreach ($costSample as $c) ln('    e.g. ' . $c);
    }
    // does account.move.line carry a margin/cost field?
    try {
        $fg = $client->callModel('account.move.line', 'fields_get', ['attributes' => ['string', 'type']]);
        $hits = [];
        foreach ((array) $fg as $fn => $meta) { if (preg_match('/cost|margin|purchase_price/i', $fn)) $hits[] = $fn; }
        ln('  account.move.line cost/margin fields: ' . (empty($hits) ? '(none — would derive cost from product.standard_price)' : implode(', ', $hits)));
    } catch (\Throwable $e) { ln('  fields_get ERR: ' . $e->getMessage()); }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
