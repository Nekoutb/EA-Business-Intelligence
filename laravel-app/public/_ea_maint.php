<?php
/** TEMP read-only probe — June 2026 PO processing time. Shows every PO APPROVED
 *  in June 2026 with its RFQ create_date vs PO date_approve side by side, the
 *  gap (calendar + business days), and flags where the two timestamps collapse
 *  together (create ≈ approve) — the likely reason the average reads low.
 *  Token-guarded, no writes. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

function bizDays(DateTimeImmutable $a, DateTimeImmutable $b): float {
    if ($b <= $a) return 0.0;
    $days = 0; $cur = $a->setTime(0, 0); $end = $b->setTime(0, 0); $g = 0;
    while ($cur < $end && $g++ < 2000) { if ((int) $cur->format('N') <= 5) $days++; $cur = $cur->modify('+1 day'); }
    return (float) $days;
}

$slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
try {
    $tenant = App\Models\Tenant::where('slug', $slug)->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    ln("════ {$tenant->name} — POs APPROVED in June 2026 (RFQ create_date vs PO date_approve) ════");
    ln('(timestamps are Odoo UTC; gap is what the KPI measures)');
    ln();

    $pos = $client->searchRead('purchase.order',
        [['state', 'in', ['purchase', 'done']], ['date_approve', '>=', '2026-06-01 00:00:00'], ['date_approve', '<=', '2026-06-30 23:59:59']],
        ['name', 'partner_id', 'create_date', 'date_order', 'date_approve'],
        ['limit' => 300, 'order' => 'date_approve asc']);

    $n = count($pos);
    ln("Found {$n} POs approved in June 2026.");
    ln();
    ln(sprintf('  %-20s %-22s %-17s %-17s %6s %5s  %s', 'PO', 'Supplier', 'RFQ created', 'PO approved', 'calD', 'bizD', 'flag'));
    ln('  ' . str_repeat('─', 104));

    $identical = 0; $sameDay = 0; $ge3 = 0; $ge10 = 0;
    $bizAll = []; $calAll = []; $bizNonZero = [];
    foreach ($pos as $p) {
        if (empty($p['create_date']) || empty($p['date_approve'])) continue;
        try {
            $c = new DateTimeImmutable((string) $p['create_date']);
            $a = new DateTimeImmutable((string) $p['date_approve']);
        } catch (\Throwable $e) { continue; }
        $cal = ($a->getTimestamp() - $c->getTimestamp()) / 86400.0;
        $biz = bizDays($c, $a);
        $bizAll[] = $biz; $calAll[] = $cal;
        if ($biz > 0) $bizNonZero[] = $biz;

        $same = ((string) $p['create_date'] === (string) $p['date_approve']);
        $flag = '';
        if ($same) { $identical++; $flag = '≡ identical'; }
        elseif ($c->format('Y-m-d') === $a->format('Y-m-d')) { $sameDay++; $flag = 'same day'; }
        if ($biz >= 3) $ge3++;
        if ($biz >= 10) $ge10++;

        $partner = is_array($p['partner_id'] ?? null) ? (string) ($p['partner_id'][1] ?? '—') : '—';
        ln(sprintf('  %-20s %-22s %-17s %-17s %6.1f %5.0f  %s',
            substr((string) $p['name'], 0, 20), substr($partner, 0, 22),
            $c->format('Y-m-d H:i'), $a->format('Y-m-d H:i'), $cal, $biz, $flag));
    }

    $cnt = count($bizAll);
    sort($bizAll); sort($calAll);
    $avgBiz = $cnt ? array_sum($bizAll) / $cnt : 0;
    $avgCal = $cnt ? array_sum($calAll) / $cnt : 0;
    $medBiz = $cnt ? $bizAll[(int) floor($cnt / 2)] : 0;
    $maxBiz = $cnt ? max($bizAll) : 0;
    $avgNonZero = count($bizNonZero) ? array_sum($bizNonZero) / count($bizNonZero) : 0;

    ln();
    ln('════ SUMMARY (June 2026) ════');
    ln("  POs approved in June           : {$cnt}");
    ln("  create_date ≡ date_approve     : {$identical}  (identical timestamp — Odoo captured NO processing lag)");
    ln("  same calendar day (biz gap 0)  : " . ($identical + $sameDay));
    ln("  took ≥ 3 business days          : {$ge3}");
    ln("  took ≥ 10 business days         : {$ge10}");
    ln(sprintf('  AVG business days (the KPI)     : %.1f', $avgBiz));
    ln(sprintf('  AVG calendar days              : %.1f', $avgCal));
    ln(sprintf('  MEDIAN business days           : %.0f', $medBiz));
    ln(sprintf('  MAX business days              : %.0f', $maxBiz));
    ln(sprintf('  AVG excluding same-day POs      : %.1f business days  (real lag where the system recorded one)', $avgNonZero));
    ln();
    ln('READ: if most rows are "≡ identical" / "same day", the KPI is understating real');
    ln('      processing time because approvals happen offline before the PO is entered/confirmed.');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
