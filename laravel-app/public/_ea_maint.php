<?php
/** TEMP read-only probe v3 — PO processing time (RFQ → approval) audit: field
 *  availability, worked examples, distribution, data-quality flags, PLUS the
 *  approval-attribution check (is date_approve the GM Alphonse Bea's approval?):
 *  double-validation config, his user + approval rights, and who authored the
 *  state→PO change on recent POs. */
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
    ln();

    // G. Approval workflow — does confirming a PO require a manager's 2nd approval?
    ln('=== G. APPROVAL WORKFLOW (res.company double validation) ===');
    try {
        $co = $client->searchRead('res.company', [], ['name', 'po_double_validation', 'po_double_validation_amount', 'currency_id'], ['limit' => 5]);
        foreach ($co as $c) {
            $ccy = is_array($c['currency_id'] ?? null) ? $c['currency_id'][1] : '';
            ln('  ' . ($c['name'] ?? '?') . ': po_double_validation=' . json_encode($c['po_double_validation'] ?? null)
               . '  threshold=' . number_format((float) ($c['po_double_validation_amount'] ?? 0)) . ' ' . $ccy);
        }
        ln('  → one_step = buyer confirms alone → date_approve = self-confirmation, NOT a manager approval');
        ln('  → two_step = POs over threshold need a 2nd approval → date_approve on those = approver action');
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln();

    // H. Any stored approver field on purchase.order?
    ln('=== H. APPROVER-RELATED FIELDS on purchase.order ===');
    try {
        $all = $client->callModel('purchase.order', 'fields_get', ['attributes' => ['string', 'type']]);
        $hit = false;
        foreach ((array) $all as $fn => $meta) {
            $label = strtolower($fn . ' ' . ($meta['string'] ?? ''));
            if (preg_match('/approv|valid|sign|manager/', $label)) {
                ln('  ' . str_pad($fn, 26) . ' → "' . ($meta['string'] ?? '') . '" (' . ($meta['type'] ?? '') . ')');
                $hit = true;
            }
        }
        if (!$hit) ln('  (none — no stored "approved_by" field; approver identity lives only in the chatter/audit log)');
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln();

    // I. Alphonse Bea — Odoo user + approval rights.
    ln('=== I. ALPHONSE BEA — user account + approval rights ===');
    $alphIds = [];
    try {
        $us = $client->searchRead('res.users', ['|', ['name', 'ilike', 'Alphonse'], ['name', 'ilike', 'Bea']], ['id', 'name', 'login'], ['limit' => 10]);
        if (empty($us)) ln('  no res.users match "Alphonse"/"Bea" (inactive, or named differently in Odoo)');
        foreach ($us as $u) { $alphIds[] = (int) $u['id']; ln('  user #' . $u['id'] . ' ' . ($u['name'] ?? '') . ' <' . ($u['login'] ?? '') . '>'); }
        $grps = $client->searchRead('res.groups', [['full_name', 'ilike', 'Purchase']], ['id', 'full_name', 'users'], ['limit' => 20]);
        foreach ($grps as $g) {
            $full = (string) ($g['full_name'] ?? '');
            if (stripos($full, 'manager') === false && stripos($full, 'administr') === false) continue;
            $mem = array_map('intval', (array) ($g['users'] ?? []));
            $inIt = array_intersect($alphIds, $mem);
            ln('  group "' . $full . '" (' . count($mem) . ' members): Alphonse ' . (empty($alphIds) ? '— (unknown)' : (empty($inIt) ? 'NOT a member' : 'IS a member')));
        }
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
    ln();

    // J. Who actually authored the state→PO change on recent POs?
    ln('=== J. WHO APPROVED — chatter author of status→Purchase Order (12 recent) ===');
    try {
        $recent = $client->searchRead('purchase.order',
            [['state', 'in', ['purchase', 'done']], ['date_approve', '>=', '2026-01-01 00:00:00']],
            ['id', 'name', 'amount_total', 'create_uid', 'date_approve'],
            ['limit' => 12, 'order' => 'date_approve desc']);
        $ids = array_map(fn ($p) => (int) $p['id'], $recent);
        $msgs = $client->searchRead('mail.message',
            [['model', '=', 'purchase.order'], ['res_id', 'in', $ids], ['tracking_value_ids', '!=', false]],
            ['res_id', 'author_id', 'date', 'tracking_value_ids'], ['limit' => 500, 'order' => 'date asc']);
        $tvIds = [];
        foreach ($msgs as $m) foreach ((array) ($m['tracking_value_ids'] ?? []) as $tid) $tvIds[(int) $tid] = true;
        $tvMap = [];
        foreach (array_chunk(array_keys($tvIds), 300) as $chunk) {
            foreach ($client->searchRead('mail.tracking.value', [['id', 'in', $chunk]], ['id', 'field_desc', 'new_value_char'], ['limit' => 1000]) as $tv) {
                $tvMap[(int) $tv['id']] = [strtolower((string) ($tv['field_desc'] ?? '')), strtolower((string) ($tv['new_value_char'] ?? ''))];
            }
        }
        $approver = [];
        foreach ($msgs as $m) {
            $rid = (int) $m['res_id'];
            if (isset($approver[$rid])) continue;
            foreach ((array) ($m['tracking_value_ids'] ?? []) as $tid) {
                $tv = $tvMap[(int) $tid] ?? null;
                if (!$tv) continue;
                $isStatus = strpos($tv[0], 'status') !== false || strpos($tv[0], 'state') !== false || strpos($tv[0], 'statut') !== false;
                $toPO = strpos($tv[1], 'purchase') !== false || strpos($tv[1], 'bon de comm') !== false || strpos($tv[1], 'achat') !== false;
                if ($isStatus && $toPO) {
                    $auth = is_array($m['author_id'] ?? null) ? (string) $m['author_id'][1] : '(system)';
                    $approver[$rid] = $auth . ' @ ' . substr((string) $m['date'], 0, 16);
                    break;
                }
            }
        }
        foreach ($recent as $p) {
            $rid = (int) $p['id'];
            $creator = is_array($p['create_uid'] ?? null) ? (string) $p['create_uid'][1] : '?';
            ln(sprintf('  %-22s %12s  RFQ-by %-16s  approved-by %s',
                substr((string) $p['name'], 0, 22),
                number_format((float) ($p['amount_total'] ?? 0)),
                substr($creator, 0, 16),
                $approver[$rid] ?? '(no status-change message logged)'));
        }
        ln('  (If "approved-by" is consistently Alphonse Bea, date_approve is his approval. If it is buyers/clerks, it is not.)');
    } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
