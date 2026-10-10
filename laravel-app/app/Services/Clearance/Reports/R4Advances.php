<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R4 — Open staff advances per holder, waived-still-owed, reconciled per holder to the advance account. */
class R4Advances extends Report
{
    public const BUCKETS = [7 => '≤7', 14 => '8–14', PHP_INT_MAX => '>14'];

    protected function body(): array
    {
        $p = $this->p;
        $cats = $p->categoriesById();
        $files = $p->filesById();
        $holders = $p->advanceHolders();
        $stateLabels = $p->d->selectionLabels('logistics.expense', 'state');

        $all = $p->openAdvances();
        $justifiable = array_values(array_filter($all, fn ($e) => !Rules::isNonJustifiable($e, $cats)));
        $nonJust = array_values(array_filter($all, fn ($e) => Rules::isNonJustifiable($e, $cats)));

        $rows = [];
        foreach (self::groupBy($justifiable, fn ($e) => $holders[(int) $e['id']]['key'] ?? 'none') as $hk => $lines) {
            $h = $holders[(int) $lines[0]['id']] ?? ['name' => '(no holder recorded)', 'partner' => null, 'source' => 'none'];
            $r = ['holder' => $h['name'], 'source' => $h['source'], 'open' => count($lines), 'total' => 0.0, 'oldest' => 0]
                + array_fill_keys(array_values(self::BUCKETS), 0.0) + ['waived' => 0.0];
            $stages = [];
            foreach ($lines as $e) {
                $d = Rules::ageDays($e['date_settled'] ?? null, $p->asOf) ?? 0;
                $r['total'] += (float) $e['amount'];
                $r['oldest'] = max($r['oldest'], $d);
                $r[Rules::bucket($d, self::BUCKETS)] += (float) $e['amount'];
                $label = $stateLabels[$e['state']] ?? $e['state'];
                $stages[$label] = ($stages[$label] ?? 0) + 1;
                $fid = $this->id($e['file_id'] ?? false);
                if ($fid && isset($files[$fid]) && Rules::isAdvanceWaived($files[$fid])) $r['waived'] += (float) $e['amount']; // R-h
            }
            $r['stage'] = implode(' · ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys($stages), $stages));
            $r['_flag'] = $r['>14'] > 0 ? 'red' : ($r['8–14'] > 0 ? 'amber' : null);
            $r['_partner'] = $h['partner'];
            $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Reconcile each holder to the advance account through their work contact.
        $co = $p->d->companyAccounts();
        $accLines = $p->accountLines([$co['clearance_advance_account_id']], 'staff advance account lines');
        $byPartner = [];
        foreach ($accLines as $l) {
            $pid = $this->id($l['partner_id'] ?? false) ?? 0;
            $byPartner[$pid] = ($byPartner[$pid] ?? 0.0) + (float) $l['balance'];
        }
        $recon = [];
        foreach ($rows as $r) {
            $ledger = $r['_partner'] ? ($byPartner[$r['_partner']] ?? 0.0) : 0.0;
            $diff = round($r['total'] - $ledger);
            $recon[] = ['holder' => $r['holder'], 'report' => $r['total'], 'ledger' => $ledger, 'difference' => $diff,
                        '_flag' => $diff != 0.0 ? 'red' : 'green'];
            if ($diff != 0.0) $this->warnings[] = "WARNING — advance holder {$r['holder']}: open advances " . Rules::xaf($r['total'])
                . ' vs account ' . Rules::xaf($ledger) . ' (difference ' . Rules::xaf($diff) . ').';
            if (!$r['_partner']) $this->warnings[] = "{$r['open']} advance(s) have no holder recorded (no employee, no partner on the settlement entry) — cannot reconcile them per holder.";
        }

        $nonRows = array_map(fn ($e) => [
            'exp' => $e['name'], 'holder' => $holders[(int) $e['id']]['name'] ?? '—', 'file' => $this->name($e['file_id'] ?? false),
            'category' => $this->name($e['category_id'] ?? false), 'amount' => (float) $e['amount'],
            'days' => Rules::ageDays($e['date_settled'] ?? null, $p->asOf),
        ], $nonJust);

        $total = self::sum($rows, 'total');
        $stale = array_sum(array_column($rows, '>14'));
        return [
            'period' => $this->asOfText(),
            'decide' => $rows
                ? ($stale > 0 ? 'Chase ' . Rules::xaf($stale) . ' XAF of advances older than 14 days — largest holder ' . $rows[0]['holder'] . '.'
                              : 'All open advances are under 14 days old.')
                : 'No open staff advances.',
            'kpis' => [
                $this->kpi('Open advances', $total, 'xaf', null, null, count($justifiable) . ' advances · ' . count($rows) . ' holders'),
                $this->kpi('Older than 14 days', $stale, 'xaf', null, $stale > 0 ? 'red' : 'green'),
                $this->kpi('Waived, still owed', self::sum($rows, 'waived'), 'xaf'),
            ],
            'notes' => (bool) array_filter($rows, fn ($r) => $r['source'] === 'settlement entry')
                ? ['Holder taken from the settlement entry where the disbursement has no employee keyed.'] : [],
            'sections' => [
                $this->section('Per holder', array_merge(
                    [['holder', 'Holder', 'text'], ['source', 'Holder from', 'text'], ['open', 'Open', 'int'], ['total', 'Total', 'xaf'], ['oldest', 'Oldest (days)', 'days']],
                    array_map(fn ($b) => [$b, $b . ' days', 'xaf'], array_values(self::BUCKETS)),
                    [['stage', 'Stage', 'text'], ['waived', 'Waived, still owed', 'xaf']]
                ), $rows, 'No open staff advances.'),
                $this->section('Reconciliation per holder to the staff advance account', [
                    ['holder', 'Holder', 'text'], ['report', 'Open advances', 'xaf'], ['ledger', 'Account balance', 'xaf'], ['difference', 'Difference', 'xaf'],
                ], $recon, 'No holders to reconcile.'),
                $this->section('Non-justifiable advances (do not wait for justification)', [
                    ['exp', 'Disbursement', 'text'], ['holder', 'Holder', 'text'], ['file', 'File', 'text'],
                    ['category', 'Category', 'text'], ['amount', 'Amount', 'xaf'], ['days', 'Days', 'days'],
                ], $nonRows, 'None.'),
            ],
            'recon' => [$this->reconRow($p->reconciliations()['advance'], 'Unjustified advances vs staff advance account (R-i)')],
        ];
    }
}
