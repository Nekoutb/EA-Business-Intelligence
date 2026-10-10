<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\ClearancePack;
use App\Services\Clearance\Rules;

/** R2 — Live files grouped by whose desk and what they wait for. */
class R2Pipeline extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $live = array_values(array_filter($p->files(), [Rules::class, 'isLiveFile']));
        $targets = $p->stepTargets();
        $maxFiles = (int) $p->thresholds['stage_max_files'];
        $stateLabels = $p->d->selectionLabels('logistics.file', 'state');
        $regimes = $p->d->selectionLabels('logistics.file', 'customs_regime');

        $detail = [];
        foreach ($live as $f) {
            $days = Rules::ageDays(ClearancePack::stateSince($f), $p->asOf);
            $targetH = $targets[ClearancePack::STATE_STEP[$f['state']] ?? ''] ?? null;
            $detail[] = [
                'file' => $f['name'], 'client' => $this->name($f['partner_id'] ?? false),
                'service' => $p->serviceCode($f), 'regime' => $regimes[$f['customs_regime'] ?? ''] ?? '—',
                'state' => $stateLabels[$f['state']] ?? $f['state'],
                'owner' => $f['stage_owner'] ?: '—', 'detail' => $f['stage_detail'] ?: '—',
                'days' => $days,
                '_flag' => ($targetH !== null && $days !== null && $days * 24 > $targetH) ? 'red' : null,
                '_target' => $targetH,
            ];
        }
        usort($detail, fn ($a, $b) => ($b['days'] ?? -1) <=> ($a['days'] ?? -1));

        $stages = [];
        foreach (self::groupBy($detail, fn ($r) => $r['owner'] . "\x1f" . $r['detail']) as $k => $rows) {
            [$owner, $what] = explode("\x1f", $k);
            $oldest = max(array_map(fn ($r) => $r['days'] ?? 0, $rows));
            $overTarget = (bool) array_filter($rows, fn ($r) => $r['_flag'] === 'red');
            $stages[] = [
                'owner' => $owner, 'detail' => $what, 'files' => count($rows), 'oldest' => $oldest,
                '_flag' => (count($rows) > $maxFiles || $overTarget) ? 'red' : null,
            ];
        }
        usort($stages, fn ($a, $b) => [$b['files'], $b['oldest']] <=> [$a['files'], $a['oldest']]);

        $red = array_filter($stages, fn ($s) => $s['_flag'] === 'red');
        $decide = $red
            ? 'Unblock ' . count($red) . ' stage' . (count($red) > 1 ? 's' : '') . ' in red first — most files: “' . $stages[0]['owner'] . ' · ' . $stages[0]['detail'] . '”.'
            : ($live ? 'No stage over its limit; keep the oldest files moving.' : 'No live files.');

        return [
            'period' => $this->asOfText(),
            'decide' => $decide,
            'kpis' => [
                $this->kpi('Live files', count($live), 'int'),
                $this->kpi('Stages in red', count($red), 'int', null, $red ? 'red' : 'green'),
                $this->kpi('Oldest file (days in state)', $detail ? $detail[0]['days'] : null, 'days'),
            ],
            'sections' => [
                $this->section('Stages — whose desk, waiting for what', [
                    ['owner', 'Desk (stage owner)', 'text'], ['detail', 'Waiting for', 'text'],
                    ['files', 'Files', 'int'], ['oldest', 'Oldest (days)', 'days'],
                ], $stages, 'No live files.', null, "Red: more than {$maxFiles} files, or the oldest file is past its turnaround target."),
                $this->section('Live files', [
                    ['file', 'File', 'text'], ['client', 'Client', 'text'], ['service', 'Service', 'text'],
                    ['regime', 'Regime', 'text'], ['state', 'State', 'text'], ['owner', 'Desk', 'text'],
                    ['detail', 'Waiting for', 'text'], ['days', 'Days in state', 'days'],
                ], $detail, 'No live files.'),
            ],
        ];
    }
}
