<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R3 — Unbilled disbursements by client, aged on date_settled, reconciled to the OOP account. */
class R3Unbilled extends Report
{
    public const BUCKETS = [15 => '0–15', 30 => '16–30', 60 => '31–60', PHP_INT_MAX => '>60'];

    protected function body(): array
    {
        $p = $this->p;
        $files = $p->filesById();
        $rows = [];
        foreach (self::groupBy($p->unbilled(), fn ($e) => $this->clientOf($e, $files)) as $client => $lines) {
            $r = ['client' => $client] + array_fill_keys(array_values(self::BUCKETS), 0.0) + ['total' => 0.0, 'lines' => count($lines)];
            $blocked = [];
            foreach ($lines as $e) {
                $b = Rules::bucket(Rules::ageDays($e['date_settled'] ?? null, $p->asOf), self::BUCKETS);
                $r[$b] += (float) $e['amount'];
                $r['total'] += (float) $e['amount'];
                $fid = $this->id($e['file_id'] ?? false);
                if ($fid && !empty($files[$fid]['stage_detail'])) $blocked[$files[$fid]['stage_detail']] = true;
            }
            $r['blocked'] = $blocked ? implode(' · ', array_keys($blocked)) : '—';
            $r['_flag'] = $r['>60'] > 0 ? 'red' : ($r['31–60'] > 0 ? 'amber' : null);
            $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        $total = ['client' => 'Total'] + array_fill_keys(array_values(self::BUCKETS), 0.0) + ['total' => 0.0, 'lines' => 0, 'blocked' => ''];
        foreach ($rows as $r) {
            foreach (self::BUCKETS as $b) $total[$b] += $r[$b];
            $total['total'] += $r['total'];
            $total['lines'] += $r['lines'];
        }

        $detail = array_map(fn ($e) => [
            'exp' => $e['name'], 'file' => $this->name($e['file_id'] ?? false), 'client' => $this->clientOf($e, $files),
            'category' => $this->name($e['category_id'] ?? false), 'amount' => (float) $e['amount'],
            'settled' => $e['date_settled'], 'days' => Rules::ageDays($e['date_settled'] ?? null, $p->asOf),
        ], $p->unbilled());
        usort($detail, fn ($a, $b) => ($b['days'] ?? 0) <=> ($a['days'] ?? 0));

        $old = $total['>60'] + $total['31–60'];
        return [
            'period' => $this->asOfText(),
            'decide' => $rows
                ? 'Bill the oldest first: ' . Rules::xaf($old) . ' XAF has waited over 30 days; largest client exposure is ' . $rows[0]['client'] . ' (' . Rules::xaf($rows[0]['total']) . ').'
                : 'Nothing paid out is waiting to be billed.',
            'kpis' => [
                $this->kpi('Unbilled disbursements', $total['total'], 'xaf'),
                $this->kpi('Over 30 days', $old, 'xaf', null, $old > 0 ? 'amber' : 'green'),
                $this->kpi('Over 60 days', $total['>60'], 'xaf', null, $total['>60'] > 0 ? 'red' : 'green'),
            ],
            'sections' => [
                $this->section('By client — aged on the payment date', array_merge(
                    [['client', 'Client', 'text']],
                    array_map(fn ($b) => [$b, $b . ' days', 'xaf'], array_values(self::BUCKETS)),
                    [['total', 'Total', 'xaf'], ['lines', 'Lines', 'int'], ['blocked', 'Blocked by (file stage)', 'text']]
                ), $rows, 'No unbilled disbursements.', $total),
                $this->section('Every unbilled disbursement', [
                    ['exp', 'Disbursement', 'text'], ['file', 'File', 'text'], ['client', 'Client', 'text'],
                    ['category', 'Category', 'text'], ['amount', 'Amount', 'xaf'], ['settled', 'Paid on', 'date'], ['days', 'Days', 'days'],
                ], $detail, 'No unbilled disbursements.'),
            ],
            'recon' => [$this->reconRow($p->reconciliations()['oop'], 'Unbilled disbursements vs out-of-pocket account (R-i)')],
        ];
    }

    private function clientOf(array $e, array $files): string
    {
        $fid = $this->id($e['file_id'] ?? false);
        return $fid && isset($files[$fid]) ? $this->name($files[$fid]['partner_id'] ?? false) : '(no file)';
    }
}
