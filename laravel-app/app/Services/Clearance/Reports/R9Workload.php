<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R9 — Open work queue (clearance.task) by kind, by originating team, and advance-justification holders. */
class R9Workload extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $tasks = $p->tasks();
        $teams = $p->d->selectionLabels('clearance.task', 'originating_team');

        $agg = function (array $rows, string $keyLabel, callable $key) {
            $out = [];
            foreach (self::groupBy($rows, $key) as $k => $rs) {
                $deadlines = array_filter(array_map(fn ($r) => Rules::localDate($r['date_deadline'] ?? null), $rs));
                $landed = array_filter(array_map(fn ($r) => $r['date_landed'] ?? null, $rs));
                $out[] = [$keyLabel => $k, 'count' => count($rs), 'amount' => self::sum($rs, 'amount'),
                          'oldest' => $deadlines ? min($deadlines) : null, 'newest' => $landed ? max($landed) : null];
            }
            usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);
            return $out;
        };

        $byKind = $agg($tasks, 'queue', fn ($t) => $t['kind_label'] ?: $t['kind']);
        foreach ($byKind as &$r) {
            $age = Rules::ageDays($r['oldest'], $p->asOf);
            $r['waiting'] = $age;
            $r['_flag'] = $age !== null && $age > 7 ? 'red' : ($age !== null && $age > 3 ? 'amber' : null);
        }
        unset($r);
        $byTeam = $agg($tasks, 'team', fn ($t) => $teams[$t['originating_team'] ?? ''] ?? '(none)');
        $justify = $agg(array_filter($tasks, fn ($t) => ($t['kind'] ?? '') === 'advance_justify'), 'holder', fn ($t) => $this->name($t['holder_user_id'] ?? false));

        $rows = array_map(fn ($t) => [
            'queue' => $t['kind_label'] ?: $t['kind'], 'file' => $this->name($t['file_id'] ?? false),
            'client' => $this->name($t['partner_id'] ?? false), 'detail' => $t['detail'] ?: '—',
            'amount' => (float) $t['amount'], 'since' => $t['date_deadline'], 'landed' => $t['date_landed'],
        ], $tasks);
        usort($rows, fn ($a, $b) => strcmp((string) $b['landed'], (string) $a['landed']));

        $cols = fn (string $k, string $label) => [[$k, $label, 'text'], ['count', 'Open', 'int'], ['amount', 'Amount', 'xaf'],
                                                   ['oldest', 'Waiting since (oldest)', 'date'], ['newest', 'Newest arrival', 'date']];
        $top = $byKind[0] ?? null;
        return [
            'period' => $this->asOfText(),
            'decide' => $top ? 'Biggest queue: “' . $top['queue'] . '” with ' . $top['count'] . ' item' . ($top['count'] > 1 ? 's' : '')
                              . ($top['waiting'] !== null ? ', oldest waiting ' . $top['waiting'] . ' days.' : '.')
                             : 'Every queue is empty.',
            'kpis' => [
                $this->kpi('Open tasks', count($tasks), 'int'),
                $this->kpi('Amount waiting', self::sum($tasks, 'amount'), 'xaf'),
                $this->kpi('Queues', count($byKind), 'int'),
            ],
            'sections' => [
                $this->section('By queue', array_merge($cols('queue', 'Queue'), [['waiting', 'Oldest (days)', 'days']]), $byKind, 'Every queue is empty.',
                    null, 'Amber: oldest item waiting over 3 days. Red: over 7 days.'),
                $this->section('By originating team', $cols('team', 'Team'), $byTeam, 'No open tasks.'),
                $this->section('Advances to justify, by holder', $cols('holder', 'Holder'), $justify, 'No advance waiting for justification.'),
                $this->section('Every open task (newest first)', [
                    ['queue', 'Queue', 'text'], ['file', 'File', 'text'], ['client', 'Client', 'text'], ['detail', 'Detail', 'text'],
                    ['amount', 'Amount', 'xaf'], ['since', 'Waiting since', 'date'], ['landed', 'Arrived', 'date'],
                ], $rows, 'No open tasks.'),
            ],
        ];
    }
}
