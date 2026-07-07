<?php
/** TEMP read-only probe — daily + MTD operating expenses (class 6 ex 60). */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function nf($x) { return number_format((float) $x); }

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    $uid = $client->authenticate();
    $cid = (int) ($client->read('res.users', [$uid], ['company_id'])[0]['company_id'][0] ?? 1);

    $day = $_GET['d'] ?? '2026-06-12';
    $mStart = substr($day, 0, 7) . '-01';

    $accts = $client->searchRead('account.account', [['code', '=like', '6%']], ['id', 'code', 'name'], ['limit' => 500, 'order' => 'code']);
    $byId = [];
    foreach ($accts as $a) { $c = (string) ($a['code'] ?? ''); if (str_starts_with($c, '6') && !str_starts_with($c, '60')) $byId[(int) $a['id']] = $c . ' ' . ($a['name'] ?? ''); }
    $ids = array_keys($byId);
    echo "Class-6 ex-60 accounts: " . count($ids) . "\n";

    $grab = function ($from, $to) use ($client, $ids, $cid) {
        $dom = [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['company_id', '=', $cid], ['date', '>=', $from], ['date', '<=', $to]];
        $g = $client->callModel('account.move.line', 'read_group', ['domain' => $dom, 'fields' => ['balance:sum'], 'groupby' => ['account_id'], 'lazy' => false]);
        $m = [];
        foreach ((array) $g as $r) { $p = $r['account_id'] ?? false; if (is_array($p)) $m[(int) $p[0]] = (float) ($r['balance'] ?? 0); }
        return $m;
    };
    $dayMap = $grab($day, $day);
    $mtdMap = $grab($mStart, $day);

    echo "DAY={$day}   MTD={$mStart}..{$day}\n\n";
    echo str_pad('Account', 44) . str_pad('Day', 14) . "MTD\n";
    $ord = $mtdMap; arsort($ord);
    $dt = 0; $mt = 0;
    foreach ($ord as $aid => $mv) {
        $dv = $dayMap[$aid] ?? 0; $dt += $dv; $mt += $mv;
        echo str_pad(substr($byId[$aid] ?? (string) $aid, 0, 43), 44) . str_pad(nf($dv), 14) . nf($mv) . "\n";
    }
    echo str_pad('TOTAL', 44) . str_pad(nf($dt), 14) . nf($mt) . "\n";
    // also: are there class-6-ex-60 lines on the day at all? count
    $cnt = $client->searchCount('account.move.line', [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['company_id', '=', $cid], ['date', '=', $day]]);
    echo "\nposted expense lines on {$day}: {$cnt}\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
