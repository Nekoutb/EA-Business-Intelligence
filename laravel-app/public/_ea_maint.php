<?php
/** TEMP read-only probe — procurement-dashboard feasibility: models/fields +
 *  live counts per indicator, per tenant. Token-guarded. No writes. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }
function nf($x) { return number_format((float) $x); }

foreach (['transatlanticd', 'kwr'] as $slug) {
    try {
        $tenant = App\Models\Tenant::where('slug', $slug)->first();
        if (!$tenant) { ln("== {$slug}: tenant not found =="); continue; }
        app(App\Tenancy\TenantContext::class)->set($tenant);
        $client = App\Odoo\OdooFactory::fromConfig();
        ln("════════ {$tenant->name} ({$slug}) · procurement module enabled: " . json_encode($tenant->moduleEnabled('procurement')) . ' ════════');

        // A. PO counts by state + open ageing buckets
        try {
            $g = $client->callModel('purchase.order', 'read_group', ['domain' => [], 'fields' => [], 'groupby' => ['state'], 'lazy' => false]);
            $states = [];
            foreach ((array) $g as $r) $states[] = ($r['state'] ?? '?') . ':' . (int) ($r['__count'] ?? 0);
            ln('A. PO states: ' . implode('  ', $states));
            $today = new DateTimeImmutable('today');
            $open = $client->searchCount('purchase.order', [['state', '=', 'purchase']]);
            $gt60 = $client->searchCount('purchase.order', [['state', '=', 'purchase'], ['date_order', '<', $today->modify('-60 days')->format('Y-m-d 00:00:00')]]);
            $gt90 = $client->searchCount('purchase.order', [['state', '=', 'purchase'], ['date_order', '<', $today->modify('-90 days')->format('Y-m-d 00:00:00')]]);
            ln("   open={$open}  ageing>60d={$gt60}  >90d={$gt90}");
        } catch (\Throwable $e) { ln('A. purchase.order ERR: ' . $e->getMessage()); }

        // B. Requisition model + PO date fields
        try {
            $client->callModel('purchase.requisition', 'search_count', ['domain' => []]);
            ln('B. purchase.requisition: EXISTS, count=' . $client->searchCount('purchase.requisition', []));
        } catch (\Throwable $e) { ln('B. purchase.requisition: ABSENT'); }
        try {
            $fg = $client->callModel('purchase.order', 'fields_get', ['allfields' => ['create_date', 'date_approve', 'date_order', 'effective_date', 'invoice_ids', 'requisition_id', 'origin', 'picking_ids'], 'attributes' => ['type']]);
            ln('   PO fields present: ' . implode(', ', array_keys((array) $fg)));
        } catch (\Throwable $e) { ln('   PO fields_get ERR: ' . $e->getMessage()); }

        // C. PR→PO proxy: create_date → date_approve gap on 2026 POs
        try {
            $pos = $client->searchRead('purchase.order', [['state', 'in', ['purchase', 'done']], ['date_approve', '>=', '2026-01-01']], ['create_date', 'date_approve'], ['limit' => 500]);
            $gaps = [];
            foreach ($pos as $p) {
                if (empty($p['create_date']) || empty($p['date_approve'])) continue;
                $gaps[] = (strtotime((string) $p['date_approve']) - strtotime((string) $p['create_date'])) / 86400;
            }
            ln('C. 2026 confirmed POs=' . count($pos) . '  create→approve gap: avg=' . (empty($gaps) ? '—' : round(array_sum($gaps) / count($gaps), 1) . 'd') . ' max=' . (empty($gaps) ? '—' : round(max($gaps), 1) . 'd'));
        } catch (\Throwable $e) { ln('C. ERR: ' . $e->getMessage()); }

        // D. After-the-fact: PO date vs earliest linked invoice date (2026)
        try {
            $pos = $client->searchRead('purchase.order', [['state', 'in', ['purchase', 'done']], ['date_order', '>=', '2026-01-01']], ['name', 'date_order', 'invoice_ids'], ['limit' => 500]);
            $withInv = 0; $after = 0; $invMap = [];
            foreach ($pos as $p) foreach ((array) ($p['invoice_ids'] ?? []) as $iid) $invMap[(int) $iid][] = $p;
            $invDates = [];
            if ($invMap) {
                foreach ($client->searchRead('account.move', [['id', 'in', array_keys($invMap)], ['state', '=', 'posted']], ['invoice_date'], ['limit' => 1000]) as $inv) $invDates[(int) $inv['id']] = (string) ($inv['invoice_date'] ?? '');
            }
            $seen = [];
            foreach ($invMap as $iid => $plist) {
                foreach ($plist as $p) {
                    $key = $p['name'];
                    if (isset($seen[$key]) || empty($invDates[$iid])) continue;
                    $seen[$key] = true; $withInv++;
                    if (substr((string) $p['date_order'], 0, 10) > $invDates[$iid]) $after++;
                }
            }
            ln("D. 2026 POs with posted invoice: {$withInv} · PO dated AFTER invoice: {$after}");
        } catch (\Throwable $e) { ln('D. ERR: ' . $e->getMessage()); }

        // E. DN-vs-invoice discrepancy fields on PO lines (2026)
        try {
            $pl = $client->callModel('purchase.order.line', 'read_group', ['domain' => [['order_id.state', 'in', ['purchase', 'done']], ['order_id.date_order', '>=', '2026-01-01']], 'fields' => ['product_qty:sum', 'qty_received:sum', 'qty_invoiced:sum', 'price_subtotal:sum'], 'groupby' => [], 'lazy' => false]);
            $r = $pl[0] ?? [];
            ln('E. 2026 PO lines: n=' . (int) ($r['__count'] ?? 0) . ' ordered=' . nf($r['product_qty'] ?? 0) . ' received=' . nf($r['qty_received'] ?? 0) . ' invoiced=' . nf($r['qty_invoiced'] ?? 0) . ' value=' . nf($r['price_subtotal'] ?? 0));
        } catch (\Throwable $e) { ln('E. purchase.order.line qty fields ERR: ' . $e->getMessage()); }

        // F. Bills vs PO-backed bills (2026) — compliance / exempt base
        try {
            $bills = $client->searchCount('account.move', [['move_type', '=', 'in_invoice'], ['state', '=', 'posted'], ['invoice_date', '>=', '2026-01-01']]);
            $g = $client->callModel('account.move.line', 'read_group', ['domain' => [['move_id.move_type', '=', 'in_invoice'], ['parent_state', '=', 'posted'], ['move_id.invoice_date', '>=', '2026-01-01'], ['purchase_line_id', '!=', false]], 'fields' => [], 'groupby' => ['move_id'], 'lazy' => false]);
            ln('F. 2026 vendor bills=' . $bills . ' · bills with ≥1 PO-linked line=' . count((array) $g));
        } catch (\Throwable $e) { ln('F. ERR: ' . $e->getMessage()); }
        ln();
    } catch (\Throwable $e) { ln('EXC(' . $slug . '): ' . $e->getMessage()); }
}
