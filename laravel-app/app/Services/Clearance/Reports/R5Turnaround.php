<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R5 — Each timed step against its target: median, P90, % late; open steps past target. */
class R5Turnaround extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $labels = $p->d->selectionLabels('clearance.turnaround', 'step');
        $targets = $p->stepTargets();
        $all = $p->turnaround();
        $done = array_filter($all, fn ($r) => !empty($r['is_done']) && Rules::inPeriod($r['completed'] ?? null, $p->from, $p->to));

        $rows = [];
        $grouped = self::groupBy($done, fn ($r) => $r['step']);
        foreach ($labels as $step => $label) {
            $g = $grouped[$step] ?? [];
            $hours = array_map(fn ($r) => (float) $r['hours_taken'], $g);
            $late = Rules::pct(count(array_filter($g, fn ($r) => !empty($r['is_late']))), count($g));
            $status = $late === null ? '—' : ($late <= 10 ? 'On target' : ($late <= 40 ? 'Watch' : 'Bottleneck'));
            $rows[] = [
                'step' => $label, 'target' => $targets[$step] ?? null,
                'median' => ($m = Rules::median($hours)) === null ? null : round($m, 1),
                'p90' => ($q = Rules::percentile($hours, 90)) === null ? null : round($q, 1),
                'done' => count($g), 'late' => $late, 'status' => $status,
                '_flag' => match ($status) { 'On target' => 'green', 'Watch' => 'amber', 'Bottleneck' => 'red', default => null },
            ];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $open = [];
        foreach ($all as $r) {
            if (!empty($r['is_done']) || empty($r['started'])) continue;
            $target = (float) ($r['target_hours'] ?? 0) ?: ($targets[$r['step']] ?? 0);
            $hoursOpen = ($now->getTimestamp() - (new \DateTimeImmutable($r['started'], new \DateTimeZone('UTC')))->getTimestamp()) / 3600;
            if ($target <= 0 || $hoursOpen <= $target) continue;
            $open[] = [
                'step' => $labels[$r['step']] ?? $r['step'], 'file' => $this->name($r['file_id'] ?? false),
                'client' => $this->name($r['partner_id'] ?? false), 'started' => $r['started'],
                'open' => round($hoursOpen, 1), 'target' => $target, 'over' => round($hoursOpen - $target, 1), '_flag' => 'red',
            ];
        }
        usort($open, fn ($a, $b) => strcmp((string) $a['started'], (string) $b['started']));

        $bottlenecks = array_filter($rows, fn ($r) => $r['status'] === 'Bottleneck');
        if (!$done) $this->warnings[] = 'No turnaround step was completed in this period — the step table shows targets only.';
        return [
            'decide' => $bottlenecks
                ? 'Fix the bottleneck step' . (count($bottlenecks) > 1 ? 's' : '') . ': ' . implode(', ', array_column($bottlenecks, 'step')) . '.'
                : ($open ? count($open) . ' open step' . (count($open) > 1 ? 's are' : ' is') . ' past target — clear the oldest first.' : 'Every step is within target.'),
            'kpis' => [
                $this->kpi('Steps completed', count($done), 'int'),
                $this->kpi('Late', Rules::pct(count(array_filter($done, fn ($r) => !empty($r['is_late']))), count($done)), 'pct'),
                $this->kpi('Open past target', count($open), 'int', null, $open ? 'red' : 'green'),
            ],
            'sections' => [
                $this->section('Per step (completed in the period)', [
                    ['step', 'Step', 'text'], ['target', 'Target (h)', 'hours'], ['median', 'Median (h)', 'hours'],
                    ['p90', 'P90 (h)', 'hours'], ['done', 'Done', 'int'], ['late', '% late', 'pct'], ['status', 'Status', 'text'],
                ], $rows, 'No steps defined.', null, 'On target ≤ 10 % late · Watch 10–40 % · Bottleneck > 40 %.'),
                $this->section('Open steps past their target (oldest first)', [
                    ['step', 'Step', 'text'], ['file', 'File', 'text'], ['client', 'Client', 'text'], ['started', 'Started', 'date'],
                    ['open', 'Open (h)', 'hours'], ['target', 'Target (h)', 'hours'], ['over', 'Over by (h)', 'hours'],
                ], $open, 'No open step is past its target.'),
            ],
        ];
    }
}
