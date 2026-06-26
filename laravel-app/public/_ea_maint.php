<?php
/** TEMP read-only probe — Kissywears AR reconciliation: invoice-residual AR vs
 *  GL class-41 balance, journal + partner-credit decomposition. Token-guarded. */
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
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first()
           ?? App\Models\Tenant::where('slug', 'like', '%kissy%')->first();
    if (!$tenant) { ln('Kissywears tenant not found'); exit; }
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    ln("Tenant: {$tenant->name} (slug={$tenant->slug}) — as of {$today}");
    ln();

    // ── A. BMS basis: open posted customer invoices/refunds, residual>0 ────
    ln('=== A. BMS Total AR — open customer invoices/refunds (Σ signed residual) ===');
    $dom = [['move_type', 'in', ['out_invoice', 'out_refund']], ['state', '=', 'posted'], ['amount_residual', '>', 0]];
    $A = 0.0; $Acount = 0;
    try {
        $g = $client->callModel('account.move', 'read_group', ['domain' => $dom, 'fields' => ['amount_residual_signed:sum', 'amount_residual:sum'], 'groupby' => ['move_type'], 'lazy' => false]);
        foreach ((array) $g as $r) {
            $A += (float) ($r['amount_residual_signed'] ?? 0); $Acount += (int) ($r['__count'] ?? 0);
            ln(sprintf('  %-12s docs=%4d  signed_residual=%14s  (abs=%s)', v($r['move_type'] ?? '?'), (int) ($r['__count'] ?? 0), nf($r['amount_residual_signed'] ?? 0), nf($r['amount_residual'] ?? 0)));
        }
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln('  A = BMS Total AR = ' . nf($A) . " over {$Acount} docs   (page shows 5.48M / 44 invoices)");
    ln();

    // ── B. GL receivable: class 41 net balance, posted, as of today ────────
    ln('=== B. GL receivable — class 41 net balance (posted, date<=today) ===');
    $B = 0.0; $arIds = [];
    try {
        $accts = $client->searchRead('account.account', [['code', '=like', '41%']], ['id', 'code', 'name'], ['order' => 'code']);
        foreach ($accts as $a) $arIds[] = (int) $a['id'];
        $domL = [['parent_state', '=', 'posted'], ['account_id', 'in', $arIds], ['date', '<=', $today]];
        $gb = $client->callModel('account.move.line', 'read_group', ['domain' => $domL, 'fields' => ['balance:sum', 'debit:sum', 'credit:sum'], 'groupby' => [], 'lazy' => false]);
        $B = (float) ($gb[0]['balance'] ?? 0);
        ln('  41 accounts: ' . count($arIds) . ' — ' . implode(', ', array_map(fn ($a) => $a['code'], array_slice($accts, 0, 12))));
        ln('  B = net balance = ' . nf($B) . '   (debit ' . nf($gb[0]['debit'] ?? 0) . ' − credit ' . nf($gb[0]['credit'] ?? 0) . ')');
        ln('      (Odoo Aged Receivable shows 3,828,830)');
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln();

    // ── C. Class-41 net by journal (invoices vs payments vs manual) ────────
    ln('=== C. Class-41 net balance by journal (where the credits sit) ===');
    try {
        $gj = $client->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $arIds], ['date', '<=', $today]], 'fields' => ['balance:sum'], 'groupby' => ['journal_id'], 'lazy' => false]);
        $rows = [];
        foreach ((array) $gj as $r) $rows[] = [v($r['journal_id'] ?? '?'), (float) ($r['balance'] ?? 0), (int) ($r['__count'] ?? 0)];
        usort($rows, fn ($a, $b) => abs($b[1]) <=> abs($a[1]));
        foreach ($rows as $r) ln(sprintf('  %-32s net=%14s  lines=%d', $r[0], nf($r[1]), $r[2]));
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln();

    // ── D. Partner credit balances (the negative rows in the aged report) ──
    ln('=== D. Partner net balances on class 41 — credits (negatives) vs debits ===');
    try {
        $gp = $client->callModel('account.move.line', 'read_group', ['domain' => [['parent_state', '=', 'posted'], ['account_id', 'in', $arIds], ['date', '<=', $today]], 'fields' => ['balance:sum'], 'groupby' => ['partner_id'], 'lazy' => false]);
        $pos = 0.0; $neg = 0.0; $nPos = 0; $nNeg = 0; $negList = [];
        foreach ((array) $gp as $r) {
            $bal = (float) ($r['balance'] ?? 0);
            if ($bal > 0) { $pos += $bal; $nPos++; }
            elseif ($bal < 0) { $neg += $bal; $nNeg++; $negList[v($r['partner_id'] ?? '?')] = $bal; }
        }
        ln('  Debit (owing us):   ' . nf($pos) . " across {$nPos} partners");
        ln('  Credit (we owe / advances): ' . nf($neg) . " across {$nNeg} partners");
        ln('  Net (= B): ' . nf($pos + $neg));
        asort($negList);
        ln('  Largest credit balances:');
        $i = 0; foreach ($negList as $name => $bal) { if ($i++ >= 10) break; ln('    ' . $name . ' : ' . nf($bal)); }
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln();

    ln('=== BRIDGE ===');
    ln('  A (BMS invoice-AR)   = ' . nf($A));
    ln('  B (GL 41 net / aged) = ' . nf($B));
    ln('  GAP A − B            = ' . nf($A - $B) . '  ← receivable items the BMS ignores (unreconciled receipts/advances/manual)');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

function v($x) { if ($x === false || $x === null || $x === '') return '—'; if (is_array($x)) return (string) ($x[1] ?? $x[0] ?? '—'); return (string) $x; }
