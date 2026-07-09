<?php
/** TEMP read-only probe — PO processing time (RFQ → approval) audit: field
 *  availability, worked examples, distribution, data-quality flags. */
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
    while ($cur < $end && $g++ < 1000) { if ((int) $cur->format('N') <= 5) $days++; $cur = $cur->modify('+1 day'); }
    return (float) $days;
}

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();

    // A. What timestamp fields exist on purchase.order?
    ln('=== A. AVAILABLE TIMESTAMPS on purchase.order (TSD) ===');
    try {
        $fg = $client->callModel('purchase.order', 'fields_get', ['allfields' => ['create_date', 'date_order', 'date_approve', 'write_date', 'requisition_id'], 'attributes' => ['string', 'type']]);
        foreach ((array) $fg as $f => $meta) ln('  ' . str_pad($f, 16) . ' → "' . ($meta['string'] ?? '?') . '" (' . ($meta['type'] ?? '?') . ')');
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    try { $client->callModel('purchase.requisition', 'search_count', ['domain' => []]); ln('  purchase.requisition model: EXISTS'); }
    catch (\Throwable $e) { ln('  purchase.requisition model: ABSENT → RFQ creation (create_date) is the proxy start point'); }
    ln();

    // B. Full YTD population with all three timestamps.
    $pos = $client->searchRead('purchase.order',
        [['state', 'in', ['purchase', 'done']], ['date_approve', '>=', '2026-01-01 00:00:00']],
        ['name', 'partner_id', 'create_date', 'date_order', 'date_approve'],
        ['limit' => 2000, 'order' => 'date_approve desc']);
    $rows = [];
    $missing = 0; $negative = 0;
    foreach ($pos as $p) {
        if (empty($p['create_date']) || empty($p['date_approve'])) { $missing++; continue; }
        try {
            $c = new DateTimeImmutable((string) $p['create_date']);
            $a = new DateTimeImmutable((string) $p['date_approve']);
        } catch (\Throwable $e) { $missing++; continue; }
        $cal = ($a->getTimestamp() - $c->getTimestamp()) / 86400.0;
        if ($cal < 0) $negative++;
        $rows[] = [
            'name' => (string) ($p['name'] ?? ''),
            'partner' => is_array($p['partner_id'] ?? null) ? (string) ($p['partner_id'][1] ?? '—') : '—',
            'created' => $c, 'approved' => $a,
            'order' => (string) ($p['date_order'] ?? ''),
            'cal' => $cal, 'biz' => bizDays($c, $a),
        ];
    }
    $n = count($rows);
    ln("=== B. POPULATION — confirmed POs approved in 2026: {$n} usable · {$missing} missing timestamps · {$negative} negative (approved before created) ===");
    ln();

    // C. Worked examples — 12 most recently approved.
    ln('=== C. WORKED EXAMPLES (12 most recent approvals) ===');
    ln(sprintf('  %-22s %-24s %-17s %-17s %-17s %6s %5s', 'PO', 'Supplier', 'RFQ created', 'Order date', 'Approved', 'CalD', 'BizD'));
    foreach (array_slice($rows, 0, 12) as $r) {
        ln(sprintf('  %-22s %-24s %-17s %-17s %-17s %6.1f %5.0f',
            substr($r['name'], 0, 22), substr($r['partner'], 0, 24),
            $r['created']->format('Y-m-d H:i'), substr($r['order'], 0, 16),
            $r['approved']->format('Y-m-d H:i'), $r['cal'], $r['biz']));
    }
    ln();

    // D. Distribution of business-day durations.
    $biz = array_map(fn ($r) => $r['biz'], $rows);
    sort($biz);
    $avg = $n ? array_sum($biz) / $n : 0;
    $med = $n ? $biz[(int) floor($n / 2)] : 0;
    $p90 = $n ? $biz[(int) floor($n * 0.9)] : 0;
    $buckets = ['same day (0)' => 0, '1 day' => 0, '2-3 days' => 0, '4-5 days' => 0, '6-10 days' => 0, '11-20 days' => 0, '>20 days' => 0];
    foreach ($biz as $d) {
        if ($d <= 0) $buckets['same day (0)']++;
        elseif ($d <= 1) $buckets['1 day']++;
        elseif ($d <= 3) $buckets['2-3 days']++;
        elseif ($d <= 5) $buckets['4-5 days']++;
        elseif ($d <= 10) $buckets['6-10 days']++;
        elseif ($d <= 20) $buckets['11-20 days']++;
        else $buckets['>20 days']++;
    }
    ln('=== D. DISTRIBUTION (business days, RFQ created → approved) ===');
    ln(sprintf('  n=%d · avg=%.1f · median=%.0f · p90=%.0f · max=%.0f', $n, $avg, $med, $p90, $n ? max($biz) : 0));
    foreach ($buckets as $lbl => $cnt) ln(sprintf('  %-14s %5d  (%4.1f%%)', $lbl, $cnt, $n ? 100 * $cnt / $n : 0));
    ln();

    // E. The 5 slowest — are they real cases or data artifacts?
    usort($rows, fn ($a, $b) => $b['biz'] <=> $a['biz']);
    ln('=== E. FIVE SLOWEST (check: real delays or artifacts?) ===');
    foreach (array_slice($rows, 0, 5) as $r) {
        ln(sprintf('  %-22s %-24s created %-11s approved %-11s = %3.0f biz d',
            substr($r['name'], 0, 22), substr($r['partner'], 0, 24),
            $r['created']->format('Y-m-d'), $r['approved']->format('Y-m-d'), $r['biz']));
    }
    ln();

    // F. Does date_order ≈ date_approve? (informational — which anchor is honest)
    $same = 0; $diff = 0;
    foreach ($rows as $r) {
        $o = substr($r['order'], 0, 10);
        if ($o !== '' && $o === $r['approved']->format('Y-m-d')) $same++; else $diff++;
    }
    ln("=== F. date_order vs date_approve (same calendar day): {$same} same · {$diff} different ===");
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
