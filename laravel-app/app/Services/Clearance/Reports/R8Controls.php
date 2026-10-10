<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R8 — Every exception in the period with approver, reason and approver concentration; refusals from the chatter (R-p). */
class R8Controls extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $in = fn ($d) => Rules::inPeriod($d, $p->from, $p->to);
        $flagShare = (float) $p->thresholds['approver_share_flag'];
        $ex = []; // type, ref, date, approver, amount, reason

        foreach ($p->files() as $f) {
            if (($f['waiver_state'] ?? '') === 'approved' && $in($f['waiver_date'] ?? null)) {
                $ex[] = ['type' => 'Document waiver', 'ref' => $f['name'], 'date' => $f['waiver_date'],
                         'approver' => $this->name($f['waiver_approved_by_id'] ?? false), 'amount' => null, 'reason' => $f['waiver_reason'] ?: '—'];
            }
            if (($f['advance_waiver_state'] ?? '') === 'approved' && $in($f['advance_waiver_date'] ?? null)) {
                $ex[] = ['type' => 'Advance waiver', 'ref' => $f['name'], 'date' => $f['advance_waiver_date'],
                         'approver' => $this->name($f['advance_waiver_approved_by_id'] ?? false),
                         'amount' => (float) $f['unjustified_advance_total'], 'reason' => $f['advance_waiver_reason'] ?: '—'];
            }
            if (($f['recharge_state'] ?? '') === 'approved' && (float) $f['recharge_variance'] < 0 && $in($f['recharge_approved_date'] ?? null)) {
                $gm = $this->name($f['recharge_gm_approved_by_id'] ?? false);
                $ex[] = ['type' => 'Below-cost recharge', 'ref' => $f['name'], 'date' => $f['recharge_approved_date'],
                         'approver' => $gm !== '—' ? $gm : $this->name($f['recharge_ops_approved_by_id'] ?? false),
                         'amount' => (float) $f['recharge_variance'], 'reason' => $f['recharge_reason'] ?: '—'];
            }
            if (($f['reopen_request_state'] ?? '') === 'approved' && $in($f['reopen_request_date'] ?? null)) {
                $ex[] = ['type' => 'Reopening', 'ref' => $f['name'], 'date' => $f['reopen_request_date'],
                         'approver' => $this->name($f['reopen_approved_by_id'] ?? false), 'amount' => null, 'reason' => $f['reopen_request_reason'] ?: '—'];
            }
        }
        $counted = $p->countedInvoiceIds();
        foreach ($p->invoices() as $m) {
            if (!empty($m['clearance_voided']) && $in($m['clearance_void_date'] ?? null)) {
                $ex[] = ['type' => 'Cancelled invoice', 'ref' => $m['name'], 'date' => $m['clearance_void_date'],
                         'approver' => '—', 'amount' => (float) $m['amount_total'], 'reason' => $m['clearance_void_reason'] ?: '—'];
            }
            if ($m['move_type'] === 'out_refund' && isset($counted[(int) $m['id']]) && $in($m['invoice_date'] ?? null)) {
                $ex[] = ['type' => 'Credit note', 'ref' => $m['name'], 'date' => $m['invoice_date'],
                         'approver' => '—', 'amount' => (float) $m['amount_total'], 'reason' => $m['clearance_credit_reason'] ?: '—'];
            }
        }
        $refusals = array_values(array_filter($p->refusals(), fn ($r) => $in($r['date'])));
        foreach ($refusals as $r) {
            $ex[] = ['type' => 'Refusal: ' . $r['kind'], 'ref' => $r['model'] . ' #' . $r['res_id'], 'date' => $r['date'],
                     'approver' => $r['author'], 'amount' => null, 'reason' => $r['has_reason'] ? $r['reason'] : '(no reason given)',
                     '_flag' => $r['has_reason'] ? null : 'red'];
            if (!$r['has_reason']) $this->warnings[] = 'FINDING — a refusal without a reason was recorded on ' . Rules::fdate($r['date'])
                . ' by ' . $r['author'] . ' (' . $r['model'] . ' #' . $r['res_id'] . '); the screens should make this impossible since 10/10/2026.';
        }
        usort($ex, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        // Summary + approver concentration per type.
        $summary = $shares = [];
        foreach (self::groupBy($ex, fn ($e) => str_starts_with($e['type'], 'Refusal') ? 'Refusals' : $e['type']) as $type => $rows) {
            $byApprover = array_count_values(array_map(fn ($r) => $r['approver'], $rows));
            arsort($byApprover);
            $top = array_key_first($byApprover);
            $topShare = Rules::pct($byApprover[$top], count($rows));
            $amounts = array_filter(array_column($rows, 'amount'), fn ($v) => $v !== null);
            $summary[] = ['type' => $type, 'count' => count($rows), 'amount' => $amounts ? array_sum($amounts) : null,
                          'top' => $top, 'share' => $topShare,
                          '_flag' => ($top !== '—' && count($rows) > 1 && $topShare > $flagShare) ? 'red' : null];
            foreach ($byApprover as $who => $n) {
                $s = Rules::pct($n, count($rows));
                $shares[] = ['type' => $type, 'approver' => $who, 'count' => $n, 'share' => $s,
                             '_flag' => ($who !== '—' && count($rows) > 1 && $s > $flagShare) ? 'red' : null];
            }
        }

        $kinds = [];
        foreach (self::groupBy($refusals, fn ($r) => $r['kind']) as $k => $rs) $kinds[] = ['kind' => $k, 'count' => count($rs)];
        usort($kinds, fn ($a, $b) => $b['count'] <=> $a['count']);
        $recent = array_map(fn ($r) => ['date' => $r['date'], 'author' => $r['author'], 'kind' => $r['kind'],
                                        'reason' => $r['has_reason'] ? $r['reason'] : '(no reason given)'], array_slice($refusals, 0, 10));

        $red = array_filter($summary, fn ($s) => $s['_flag'] === 'red');
        return [
            'decide' => $red
                ? 'Approval is concentrated: ' . implode('; ', array_map(fn ($s) => "{$s['top']} holds " . Rules::fpct($s['share']) . " of {$s['type']}", $red)) . '.'
                : ($ex ? count($ex) . ' exceptions recorded; no approver above ' . Rules::fpct($flagShare) . ' of any type.' : 'No exceptions recorded in this period.'),
            'kpis' => [
                $this->kpi('Exceptions', count($ex), 'int'),
                $this->kpi('Refusals', count($refusals), 'int'),
                $this->kpi('Concentration flags', count($red), 'int', null, $red ? 'red' : 'green'),
            ],
            'sections' => [
                $this->section('Exceptions by type', [
                    ['type', 'Type', 'text'], ['count', 'Count', 'int'], ['amount', 'Amount', 'xaf'], ['top', 'Main approver', 'text'], ['share', 'Their share', 'pct'],
                ], $summary, 'No exceptions recorded in this period.', null, 'Red: one approver holds more than ' . Rules::fpct($flagShare) . ' of a type.'),
                $this->section('Approver share per type', [
                    ['type', 'Type', 'text'], ['approver', 'Approver', 'text'], ['count', 'Count', 'int'], ['share', 'Share', 'pct'],
                ], $shares, 'No exceptions recorded in this period.'),
                $this->section('Refusals by kind', [['kind', 'Kind', 'text'], ['count', 'Count', 'int']], $kinds, 'No refusals in this period.'),
                $this->section('Ten most recent refusal reasons', [
                    ['date', 'Date', 'date'], ['author', 'By', 'text'], ['kind', 'Kind', 'text'], ['reason', 'Reason', 'text'],
                ], $recent, 'No refusals in this period.'),
                $this->section('Every exception', [
                    ['type', 'Type', 'text'], ['ref', 'Reference', 'text'], ['date', 'Date', 'date'], ['approver', 'Approver', 'text'],
                    ['amount', 'Amount', 'xaf'], ['reason', 'Reason', 'text'],
                ], $ex, 'No exceptions recorded in this period.'),
            ],
        ];
    }
}
