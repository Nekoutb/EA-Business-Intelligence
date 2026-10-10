<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R11 — Payments in transit per bank holding account (R-m), with the line detail and reconciliation. */
class R11Transit extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $banks = $p->transitByBank();
        $flagDays = (int) $p->thresholds['transit_flag_wdays'];

        // Settlement entries → file, read once for every open line.
        $moveIds = [];
        foreach ($banks as $b) foreach ($b['open'] as $l) if ($id = $this->id($l['move_id'] ?? false)) $moveIds[$id] = true;
        $moves = $moveIds ? array_column($p->d->searchAll('account.move', [['id', 'in', array_keys($moveIds)]],
            ['id', 'name', 'logistics_file_id'], 'settlement entries'), null, 'id') : [];

        $summary = $detail = $recon = [];
        foreach ($banks as $b) {
            $oldest = null;
            foreach ($b['open'] as $l) {
                $days = Rules::ageDays($l['date'] ?? null, $p->asOf);
                $wd = Rules::workingDays($l['date'] ?? null, $p->asOf);
                $oldest = max($oldest ?? 0, $days ?? 0);
                $mid = $this->id($l['move_id'] ?? false);
                $detail[] = [
                    'bank' => $b['journal']['name'], 'date' => $l['date'], 'payee' => $this->name($l['partner_id'] ?? false),
                    'file' => $this->name($moves[$mid]['logistics_file_id'] ?? false), 'entry' => $moves[$mid]['name'] ?? $this->name($l['move_id']),
                    'amount' => -(float) $l['balance'], 'days' => $days, 'wdays' => $wd,
                    '_flag' => $wd !== null && $wd > $flagDays ? 'red' : null,
                ];
            }
            $summary[] = [
                'bank' => $b['journal']['name'], 'holding' => $b['journal']['holding_account_name'] ?? '—',
                'issued' => $b['issued'], 'cleared' => $b['cleared'], 'transit' => -$b['transit'],
                'oldest' => $oldest, 'balance' => $b['bank_balance'], 'available' => $b['available'],
            ];
            $recon[] = $this->reconRow($b['recon'], "In transit vs holding account — {$b['journal']['name']} (R-m)");
        }
        usort($detail, fn ($a, $b) => ($b['days'] ?? 0) <=> ($a['days'] ?? 0));

        $without = array_values(array_filter($p->journals(), fn ($j) => $j['type'] === 'bank' && !$j['holding_account_id']));
        if ($without) {
            $this->warnings[] = 'No holding (outstanding payments) account is configured on ' . implode(', ', array_column($without, 'name'))
                . ' — payments through these banks credit the bank account directly, so they cannot be tracked in transit. '
                . 'Set the outgoing payment method\'s "Outstanding Payments" account on each bank journal (elite_clearance 19.0.45).';
        }
        $old = array_filter($detail, fn ($d) => $d['_flag'] === 'red');
        return [
            'decide' => !$banks ? 'No bank journal has a holding account yet — configure them so payments in transit become visible.'
                : ($old ? count($old) . ' payment' . (count($old) > 1 ? 's have' : ' has') . " been in transit over {$flagDays} working days — match them to the bank statement."
                        : 'Every payment in transit is recent.'),
            'kpis' => [
                $this->kpi('In transit', array_sum(array_column($summary, 'transit')), 'xaf', null, null, count($detail) . ' lines'),
                $this->kpi("Older than {$flagDays} working days", count($old), 'int', null, $old ? 'red' : 'green'),
                $this->kpi('Banks tracked', count($banks), 'int', null, $without ? 'amber' : null, count($without) . ' without holding account'),
            ],
            'sections' => [
                $this->section('Per bank', [
                    ['bank', 'Bank', 'text'], ['holding', 'Holding account', 'text'], ['issued', 'Issued in period', 'xaf'],
                    ['cleared', 'Cleared by statement', 'xaf'], ['transit', 'In transit', 'xaf'], ['oldest', 'Oldest (days)', 'days'],
                    ['balance', 'Bank balance', 'xaf'], ['available', 'Available', 'xaf'],
                ], $summary, 'No bank journal has a holding account configured.'),
                $this->section('Every line in transit', [
                    ['bank', 'Bank', 'text'], ['date', 'Date', 'date'], ['payee', 'Vendor / staff', 'text'], ['file', 'File', 'text'],
                    ['entry', 'Settlement entry', 'text'], ['amount', 'Amount', 'xaf'], ['days', 'Days', 'days'], ['wdays', 'Working days', 'days'],
                ], $detail, 'No payment is in transit.', null, "Red: in transit more than {$flagDays} working days."),
            ],
            'recon' => $recon,
        ];
    }
}
