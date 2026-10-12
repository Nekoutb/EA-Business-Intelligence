<?php

namespace App\Services\Clearance;

use App\Services\LogisticsOpsService;

/**
 * Logistics pages and dashboard cards fed by Odoo's clearance application
 * (elite_clearance) instead of the Teese warehouse. Same output shapes as
 * LogisticsOpsService::dossiers / oopCompliance / unbilledDebours / volumes
 * so the existing views render unchanged. Read-only (ClearanceData).
 *
 *  Files      logistics.file — counted files (not imported/cancelled);
 *             opened = date_opened, closed = date_closed.
 *  OOP        incurred  = disbursements (logistics.expense, non-legacy)
 *                         paid out (date_settled) in the window;
 *             recharged = disbursements billed to the client (is_billed,
 *                         billed invoice standing) whose invoice date is in
 *                         the window, at recharge_amount (amount when unset).
 *  Unbilled   disbursements paid out in the window, settled/justified and
 *             not billed — R-f, the same rule as Clearance report R3.
 *  Volumes    container_count / weight_kg / cargo_value on files opened in
 *             the window; shipment_type for the mode split.
 */
class ClearanceOps
{
    public function __construct(private ClearancePack $p) {}

    /** Live pack over Odoo for a window (the period's own dates). */
    public static function forWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $d = ClearanceData::live(\App\Odoo\OdooFactory::fromConfig());
        return new self(new ClearancePack($d, $from->format('Y-m-d'), $to->format('Y-m-d'), '',
            ['db' => (string) config_env('ODOO_DB', ''), 'user' => (string) config_env('ODOO_USERNAME', '')]));
    }

    /** Tenant reads its logistics figures from the clearance application. */
    public static function enabled(): bool
    {
        return strtolower((string) config_env('LOGISTICS_SOURCE', '')) === 'clearance';
    }

    private function d(mixed $v): ?string
    {
        return Rules::localDate($v);
    }

    private function fileLabel(array $f): array
    {
        return [
            'code'   => (string) ($f['name'] ?? ('#' . $f['id'])),
            'client' => ClearanceData::m2oName($f['partner_id'] ?? false, '') ?: '—',
        ];
    }

    private function countedFiles(): array
    {
        return array_values(array_filter($this->p->files(), [Rules::class, 'isCountedFile']));
    }

    /** Open on $asOf: opened by then, not closed by then. Optional cohort start. */
    private function openOn(string $asOf, ?string $openedFrom = null): array
    {
        $out = [];
        foreach ($this->countedFiles() as $f) {
            $o = $this->d($f['date_opened'] ?? null);
            if (!$o || $o > $asOf || ($openedFrom && $o < $openedFrom)) continue;
            $c = $this->d($f['date_closed'] ?? null);
            if ($c && $c <= $asOf) continue;
            $out[] = $f + ['_age' => (int) (new \DateTimeImmutable($o))->diff(new \DateTimeImmutable($asOf))->days];
        }
        return $out;
    }

    private function backlog(string $asOf, bool $withRows = false, ?string $openedFrom = null): array
    {
        $out = ['as_of' => $asOf, 'n' => 0, 'buckets' => [], 'cum' => [], 'median' => null, 'mix' => [], 'rows' => []];
        foreach (LogisticsOpsService::AGE_BUCKETS as [$k]) { $out['buckets'][$k] = 0; $out['mix'][$k] = 0.0; }
        $files = $this->openOn($asOf, $openedFrom);
        usort($files, fn ($a, $b) => $b['_age'] <=> $a['_age']);
        $ages = [];
        foreach ($files as $f) {
            $age = $f['_age']; $ages[] = $age;
            $bucket = '90+';
            foreach (LogisticsOpsService::AGE_BUCKETS as [$k, $lo, $hi]) { if ($age >= $lo && $age <= $hi) { $bucket = $k; break; } }
            $out['buckets'][$bucket]++;
            if ($withRows) {
                $out['rows'][] = $this->fileLabel($f) + ['bucket' => $bucket,
                    'type' => ClearanceData::m2oName($f['service_type_id'] ?? false, '') ?: null,
                    'port' => ClearanceData::m2oName($f['port_id'] ?? false, '') ?: null,
                    'employee' => ClearanceData::m2oName($f['employee_id'] ?? false, '') ?: (ClearanceData::m2oName($f['user_id'] ?? false, '') ?: null),
                    'opened' => (string) $this->d($f['date_opened'] ?? null), 'age' => $age];
            }
        }
        $out['n'] = count($ages);
        sort($ages);
        $out['median'] = $ages ? $ages[(int) floor(count($ages) / 2)] : null;
        foreach ([30, 40, 60, 90] as $t) $out['cum']['>' . $t] = count(array_filter($ages, fn ($x) => $x > $t));
        if ($ages) foreach ($out['buckets'] as $k => $n) $out['mix'][$k] = round(100 * $n / count($ages), 1);
        return $out;
    }

    public function dossiers(\DateTimeImmutable $from, \DateTimeImmutable $to, string $grain = 'month'): array
    {
        $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');
        $out = ['open_now' => 0, 'opened' => 0, 'closed' => 0, 'median_age' => null, 'series' => [], 'grain' => $grain,
                'aging' => [], 'cum' => [], 'type_port' => [], 'finding' => null, 'today' => null, 'baseline' => null,
                'baseline_label' => null, 'closure' => null, 'detail' => [], 'source' => 'clearance'];

        $today = $this->backlog($t, true, $f);
        $all   = $this->backlog($t);
        $out['open_now'] = $today['n']; $out['open_all'] = $all['n'];
        $out['median_age'] = $today['median']; $out['aging'] = $today['buckets']; $out['cum'] = $today['cum'];
        foreach ($today['rows'] as $r) $out['detail'][$r['bucket']][$r['client']][] = $r;
        foreach ($out['detail'] as &$byClient) uasort($byClient, fn ($x, $y) => count($y) <=> count($x));
        unset($byClient);
        unset($today['rows']);
        $out['today'] = $today;

        $monthly = $from->format('Y-m') === $to->format('Y-m');
        $base = $monthly ? $from->modify('+14 days') : $from->modify('last day of this month');
        if ($base > $to) $base = $to;
        $out['baseline_label'] = ($monthly ? $base->format('j F Y') : 'End of ' . $base->format('F Y'))
            . ' — files opened since ' . $from->format('j M Y') . ' that were open on that date';
        $out['baseline'] = $this->backlog($base->format('Y-m-d'), false, $f);

        $files = $this->countedFiles();
        $opened = array_filter($files, fn ($x) => Rules::inPeriod($x['date_opened'] ?? null, $f, $t));
        $closed = array_filter($files, fn ($x) => Rules::inPeriod($x['date_closed'] ?? null, $f, $t));
        $out['opened'] = count($opened); $out['closed'] = count($closed);

        $start = $this->backlog($from->modify('-1 day')->format('Y-m-d'));
        $ttc = [];
        foreach ($closed as $x) {
            $o = $this->d($x['date_opened'] ?? null); $c = $this->d($x['date_closed'] ?? null);
            if ($o && $c) $ttc[] = (int) (new \DateTimeImmutable($o))->diff(new \DateTimeImmutable($c))->days;
        }
        sort($ttc);
        $wd = 0;
        for ($c = $from; $c <= $to; $c = $c->modify('+1 day')) { if ((int) $c->format('N') <= 5) $wd++; }
        $workload = $start['n'] + $out['opened'];
        $out['closure'] = [
            'backlog_start' => $start['n'], 'opened' => $out['opened'], 'closed' => $out['closed'], 'backlog_end' => $all['n'],
            'workload' => $workload, 'rate' => $workload > 0 ? round(100 * $out['closed'] / $workload, 1) : null,
            'closed_vs_opened' => $out['opened'] > 0 ? round(100 * $out['closed'] / $out['opened'], 1) : null,
            'per_working_day' => $wd > 0 ? round($out['closed'] / $wd, 2) : null, 'working_days' => $wd,
            'median_days_to_close' => $ttc ? $ttc[(int) floor(count($ttc) / 2)] : null,
        ];

        $key = fn (string $date) => match ($grain) { 'day' => $date, 'week' => (new \DateTimeImmutable($date))->format('o-\WW'), default => substr($date, 0, 7) };
        $got = [];
        foreach ($opened as $x) { $k = $key((string) $this->d($x['date_opened'])); $got[$k] = ($got[$k] ?? 0) + 1; }
        foreach (LogisticsOpsService::buckets($from, $to, $grain) as $b => $label) {
            $out['series'][] = ['bucket' => $b, 'label' => $label, 'n' => $got[$b] ?? 0];
        }

        $tp = [];
        foreach ($opened as $x) {
            $type = ClearanceData::m2oName($x['service_type_id'] ?? false, '') ?: '?';
            $port = ClearanceData::m2oName($x['port_id'] ?? false, '') ?: '—';
            $c = $this->d($x['date_closed'] ?? null);
            $tp[$type . '|' . $port] ??= ['type' => $type, 'port' => $port, 'open_n' => 0, 'total' => 0];
            $tp[$type . '|' . $port]['total']++;
            if (!$c || $c > $t) $tp[$type . '|' . $port]['open_n']++;
        }
        usort($tp, fn ($a, $b) => $b['total'] <=> $a['total']);
        $out['type_port'] = array_map(fn ($r) => (object) $r, array_slice($tp, 0, 10));
        return $out;
    }

    /** Disbursements actually paid out (settled or beyond), non-legacy. */
    private function paidOut(): array
    {
        return array_values(array_filter($this->p->expenses(), fn ($e) => empty($e['is_legacy'])
            && ($e['state'] ?? '') !== 'cancel' && $this->d($e['date_settled'] ?? null)));
    }

    private function expenseLabel(array $e): string
    {
        $cat = $this->p->categoriesById()[ClearanceData::m2oId($e['category_id'] ?? false)]['name'] ?? null;
        $desc = trim((string) ($e['description'] ?: ''));
        return trim(($cat ?: '') . ($desc !== '' ? ($cat ? ' — ' : '') . $desc : '')) ?: (string) ($e['name'] ?? '');
    }

    private function payee(array $e): string
    {
        return ClearanceData::m2oName($e['vendor_id'] ?? false, '') ?: (ClearanceData::m2oName($e['employee_id'] ?? false, '') ?: '—');
    }

    public function oopCompliance(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');
        $out = ['files' => [], 'incurred' => 0.0, 'recharged' => 0.0, 'over_n' => 0, 'under_n' => 0, 'nil_n' => 0, 'detail' => [], 'source' => 'clearance'];
        $inv = $this->p->invoicesById();
        $per = [];
        foreach ($this->paidOut() as $e) {
            $fid = ClearanceData::m2oId($e['file_id'] ?? false);
            if (!$fid) continue;
            if (Rules::inPeriod($e['date_settled'] ?? null, $f, $t)) {
                $per[$fid]['incurred'][] = ['label' => $this->expenseLabel($e), 'who' => $this->payee($e),
                    'date' => (string) $this->d($e['date_settled']), 'amount' => (float) $e['amount']];
            }
            $bid = ClearanceData::m2oId($e['billed_invoice_id'] ?? false);
            if (!empty($e['is_billed']) && $bid && isset($inv[$bid]) && Rules::billStands($inv[$bid])
                && Rules::inPeriod($inv[$bid]['invoice_date'] ?? null, $f, $t)) {
                $amt = (float) ($e['recharge_amount'] ?: $e['amount']);
                $per[$fid]['recharged'][] = ['label' => $this->expenseLabel($e), 'inv' => (string) ($inv[$bid]['name'] ?? ''),
                    'date' => (string) $this->d($inv[$bid]['invoice_date']), 'amount' => $amt];
            }
        }
        $byId = $this->p->filesById();
        foreach ($per as $fid => $d) {
            $i = array_sum(array_column($d['incurred'] ?? [], 'amount'));
            $r = array_sum(array_column($d['recharged'] ?? [], 'amount'));
            $gap = $r - $i;
            $lab = isset($byId[$fid]) ? $this->fileLabel($byId[$fid]) : ['code' => '#' . $fid, 'client' => '—'];
            $out['files'][] = ['id' => (string) $fid, 'code' => $lab['code'], 'client' => $lab['client'],
                'incurred' => $i, 'recharged' => $r, 'gap' => $gap,
                'gap_pct' => $i > 0 ? 100.0 * $gap / $i : ($gap > 0 ? null : 0.0)];
            $out['detail'][(string) $fid] = ['incurred' => $d['incurred'] ?? [], 'recharged' => $d['recharged'] ?? []];
            $out['incurred'] += $i; $out['recharged'] += $r;
            if (abs($gap) < 1) $out['nil_n']++; elseif ($gap > 0) $out['over_n']++; else $out['under_n']++;
        }
        usort($out['files'], fn ($a, $b) => abs($b['gap']) <=> abs($a['gap']));
        return $out;
    }

    public function unbilledDebours(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');
        $out = ['count' => 0, 'total' => 0.0, 'items' => [], 'by_client' => [], 'source' => 'clearance'];
        $byId = $this->p->filesById();
        foreach ($this->p->unbilled() as $e) {
            if (!Rules::inPeriod($e['date_settled'] ?? null, $f, $t)) continue;
            $fid = ClearanceData::m2oId($e['file_id'] ?? false);
            $lab = $fid && isset($byId[$fid]) ? $this->fileLabel($byId[$fid]) : ['code' => '—', 'client' => '—'];
            $out['items'][] = ['client' => $lab['client'], 'file' => $lab['code'], 'who' => $this->payee($e),
                'label' => $this->expenseLabel($e), 'date' => (string) $this->d($e['date_settled']), 'amount' => (float) $e['amount']];
            $out['total'] += (float) $e['amount'];
        }
        $out['count'] = count($out['items']);
        usort($out['items'], fn ($x, $y) => $y['amount'] <=> $x['amount']);
        $byC = [];
        foreach ($out['items'] as $it) {
            $byC[$it['client']] ??= ['client' => $it['client'], 'n' => 0, 'amt' => 0.0];
            $byC[$it['client']]['n']++; $byC[$it['client']]['amt'] += $it['amount'];
        }
        usort($byC, fn ($x, $y) => $y['amt'] <=> $x['amt']);
        $out['by_client'] = array_values($byC);
        return $out;
    }

    public function volumes(\DateTimeImmutable $from, \DateTimeImmutable $to, string $grain = 'month'): array
    {
        $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');
        $out = ['tons' => 0.0, 'containers' => 0, 'packages' => 0, 'cargo_value' => 0.0, 'series' => [], 'grain' => $grain,
                'embarquement' => [], 'importers' => [], 'source' => 'clearance',
                'capture_note' => 'Packages are not captured on clearance files; tonnage, containers and cargo value come from each file.'];
        $win = array_filter($this->countedFiles(), fn ($x) => Rules::inPeriod($x['date_opened'] ?? null, $f, $t));
        $key = fn (string $date) => match ($grain) { 'day' => $date, 'week' => (new \DateTimeImmutable($date))->format('o-\WW'), default => substr($date, 0, 7) };
        $got = []; $mode = []; $imp = [];
        foreach ($win as $x) {
            $kg = (float) ($x['weight_kg'] ?? 0); $c = (int) ($x['container_count'] ?? 0);
            $out['tons'] += $kg / 1000; $out['containers'] += $c; $out['cargo_value'] += (float) ($x['cargo_value'] ?? 0);
            $b = $key((string) $this->d($x['date_opened']));
            $got[$b] ??= ['tons' => 0.0, 'containers' => 0, 'packages' => 0, 'files' => 0];
            $got[$b]['tons'] += $kg / 1000; $got[$b]['containers'] += $c; $got[$b]['files']++;
            $m = (string) (($x['shipment_type'] ?? '') ?: '—');
            $mode[$m] = ($mode[$m] ?? 0.0) + $kg / 1000;
            $cl = ClearanceData::m2oName($x['partner_id'] ?? false, '') ?: '—';
            $imp[$cl] ??= ['k' => $cl, 'n' => 0, 't' => 0.0];
            $imp[$cl]['n']++; $imp[$cl]['t'] += $kg / 1000;
        }
        $out['tons'] = round($out['tons']);
        foreach (LogisticsOpsService::buckets($from, $to, $grain) as $b => $label) {
            $r = $got[$b] ?? ['tons' => 0.0, 'containers' => 0, 'packages' => 0, 'files' => 0];
            $r['tons'] = round($r['tons'], 1);
            $out['series'][] = ['bucket' => $b, 'label' => $label] + $r;
        }
        arsort($mode);
        foreach ($mode as $k => $tons) $out['embarquement'][] = (object) ['k' => $k, 't' => round($tons)];
        usort($imp, fn ($a, $b) => $b['t'] <=> $a['t']);
        $out['importers'] = array_map(fn ($r) => (object) $r, array_slice($imp, 0, 30));
        return $out;
    }
}
