<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R10 — Disbursements paid in the period by channel, payment mode and category; top vendors. */
class R10CashOut extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $cats = $p->categoriesById();
        $journals = array_column($p->journals(), null, 'id');
        $modes = $p->d->selectionLabels('logistics.expense', 'payment_mode');
        $paid = array_values(array_filter($p->expenses(), fn ($e) => ($e['state'] ?? '') !== 'cancel'
            && Rules::inPeriod($e['date_settled'] ?? null, $p->from, $p->to)));
        $total = self::sum($paid, 'amount');

        $byJournal = [];
        foreach (self::groupBy($paid, fn ($e) => $this->id($e['journal_id'] ?? false) ?? 0) as $jid => $rs) {
            $j = $journals[$jid] ?? null;
            $type = $j['type'] ?? '—';
            $note = $type === 'cash' ? 'Till — credited directly'
                : (!empty($j['holding_account_id']) ? 'Credited to holding account — see R11' : 'Bank with NO holding account configured');
            $byJournal[] = ['channel' => $j['name'] ?? ($jid ? $this->name($rs[0]['journal_id']) : '(no journal)'), 'type' => $type,
                            'count' => count($rs), 'amount' => self::sum($rs, 'amount'), 'share' => Rules::pct(self::sum($rs, 'amount'), $total),
                            'note' => $note, '_flag' => ($type === 'bank' && empty($j['holding_account_id'])) ? 'amber' : null];
        }
        usort($byJournal, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        $simple = function (callable $key, string $k) use ($paid, $total) {
            $out = [];
            foreach (self::groupBy($paid, $key) as $v => $rs) {
                $out[] = [$k => $v, 'count' => count($rs), 'amount' => self::sum($rs, 'amount'), 'share' => Rules::pct(self::sum($rs, 'amount'), $total)];
            }
            usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount']);
            return $out;
        };
        $byMode = $simple(fn ($e) => $modes[$e['payment_mode'] ?? ''] ?? '—', 'mode');
        $byCat = $simple(fn ($e) => $this->name($e['category_id'] ?? false) . (Rules::isNonJustifiable($e, $cats) ? ' (non-justifiable)' : ''), 'category');
        $vendors = array_slice($simple(fn ($e) => $this->name($e['vendor_id'] ?? false, ) !== '—'
            ? $this->name($e['vendor_id']) : ($this->name($e['employee_id'] ?? false) . ' (advance)'), 'vendor'), 0, 10);

        $cashAmt = self::sum(array_filter($paid, fn ($e) => ($journals[$this->id($e['journal_id'] ?? false)]['type'] ?? '') === 'cash'), 'amount');
        $nonJust = self::sum(array_filter($paid, fn ($e) => Rules::isNonJustifiable($e, $cats)), 'amount');
        $this->p->d->expectRows($paid, 'disbursements paid');

        $cols = fn (string $k, string $label) => [[$k, $label, 'text'], ['count', 'Count', 'int'], ['amount', 'Amount', 'xaf'], ['share', 'Share', 'pct']];
        return [
            'decide' => $paid
                ? Rules::xaf($total) . ' XAF paid out; ' . Rules::fpct(Rules::pct($cashAmt, $total)) . ' in cash and '
                  . Rules::fpct(Rules::pct($nonJust, $total)) . ' in non-justifiable categories.'
                : 'Nothing was paid out in this period.',
            'kpis' => [
                $this->kpi('Paid out', $total, 'xaf', null, null, count($paid) . ' disbursements'),
                $this->kpi('Share in cash', Rules::pct($cashAmt, $total), 'pct'),
                $this->kpi('Share non-justifiable', Rules::pct($nonJust, $total), 'pct'),
            ],
            'sections' => [
                $this->section('By payment channel', [
                    ['channel', 'Channel (journal)', 'text'], ['type', 'Type', 'text'], ['count', 'Count', 'int'],
                    ['amount', 'Amount', 'xaf'], ['share', 'Share', 'pct'], ['note', 'Accounting', 'text'],
                ], $byJournal, 'Nothing paid out in this period.'),
                $this->section('By payment mode', $cols('mode', 'Mode'), $byMode, 'Nothing paid out in this period.'),
                $this->section('By category', $cols('category', 'Category'), $byCat, 'Nothing paid out in this period.'),
                $this->section('Top 10 vendors', $cols('vendor', 'Vendor / staff'), $vendors, 'Nothing paid out in this period.'),
            ],
        ];
    }
}
