<?php

namespace App\Services\ClearanceIntel;

use App\Odoo\OdooFactory;
use App\Services\Clearance\ClearanceData;
use App\Services\Clearance\Rules;

/**
 * Builds (and caches) the Clearance Intelligence payload for a window:
 * KPIs with previous period, targets and RAG; alerts; breakdowns; trends;
 * forecast; per-file facts for the drilldown.
 */
final class Report
{
    public const CACHE = 'clri_v1_';

    public static function build(string $from, string $to, bool $refresh = false): array
    {
        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone(Rules::TZ)))->format('Y-m-d');
        $bucket = intdiv(time(), 1800) . ($refresh ? '-' . time() : '');
        $key = self::CACHE . md5(json_encode([$from, $to, $asOf, Targets::fingerprint(),
            \App\Services\ProductivityOverrides::fingerprint(), $bucket]));
        return ea_cached($key, function () use ($from, $to, $asOf) {
            $d = ClearanceData::live(OdooFactory::fromConfig());
            $ds = Dataset::load($d, $asOf);
            return self::compute($ds, $from, $to, $asOf);
        });
    }

    public static function compute(Dataset $ds, string $from, string $to, string $asOf): array
    {
        $e = new Engine($ds, $asOf);
        [$pf, $pt] = Engine::previousWindow($from, $to);
        $cur = $e->kpis($from, $to);
        $prev = $e->kpis($pf, $pt);
        $fc = $e->forecast($to);
        $cur += ['fy_files' => $fc['fy_files'], 'fy_fees' => $fc['fy_fees'], 'pipeline_fees' => $fc['pipeline_fees'],
                 'pipeline_debours' => $fc['pipeline_debours']];

        $kpis = []; $alerts = [];
        foreach ((array) config('clearance_intel.kpis') as $k => $def) {
            $v = $cur[$k] ?? null; $p = $prev[$k] ?? null;
            $t = Targets::forKpi($k);
            $rag = Targets::rag($k, $v === null ? null : (float) $v);
            $kpis[$k] = [
                'key' => $k, 'section' => $def[0], 'label' => $def[1], 'unit' => $def[2], 'better' => $def[3],
                'definition' => $def[4], 'formula' => $def[5], 'target' => $t['target'], 'trigger' => $t['trigger'],
                'value' => $v, 'prev' => in_array($def[0], ['forecast'], true) ? null : $p, 'rag' => $rag,
            ];
            if ($rag === 'red') $alerts[] = $k;
        }

        $flagCounts = array_fill_keys(array_keys((array) config('clearance_intel.triggers')), 0);
        foreach ($e->facts as $f) foreach ($f['flags'] as $fl) $flagCounts[$fl] = ($flagCounts[$fl] ?? 0) + 1;

        return [
            'from' => $from, 'to' => $to, 'prev_from' => $pf, 'prev_to' => $pt, 'as_of' => $asOf,
            'extracted' => (new \DateTimeImmutable('now', new \DateTimeZone(Rules::TZ)))->format('d/m/Y H:i'),
            'kpis' => $kpis, 'alerts' => $alerts, 'flag_counts' => $flagCounts,
            'breakdowns' => $e->breakdowns($from, $to),
            'trends' => $e->trends($to),
            'forecast' => $fc,
            'files' => array_values($e->facts),
            'headcount_source' => $ds->headcountByMonth ? 'Productivity settings' : 'employee profiles',
            'warnings' => $ds->warnings,
            'queries' => count($ds->queryLog),
        ];
    }

    /** One file's own records, for the drilldown page (cached 10 minutes). */
    public static function file(int $id): array
    {
        return ea_cached('clri_file_v1_' . $id . '_' . intdiv(time(), 600), function () use ($id) {
            $d = ClearanceData::live(OdooFactory::fromConfig());
            $f = config('clearance_intel_fields');
            $file = $d->searchAll('logistics.file', [['id', '=', $id]], $f['logistics.file'], 'file')[0] ?? null;
            if (!$file) return ['file' => null];
            $moves = $d->searchAll('account.move', [['logistics_file_id', '=', $id]], $f['account.move'], 'file invoices');
            $lines = $moves ? $d->searchAll('account.move.line', [['move_id', 'in', array_column($moves, 'id')],
                ['clearance_category', '!=', false]], $f['account.move.line'], 'file invoice lines') : [];
            return [
                'file' => $file,
                'expenses' => $d->searchAll('logistics.expense', [['file_id', '=', $id]], $f['logistics.expense'], 'file disbursements', 'id'),
                'moves' => $moves,
                'lines' => $lines,
                'steps' => $d->searchAll('clearance.turnaround', [['file_id', '=', $id]], $f['clearance.turnaround'], 'file steps'),
                'documents' => $d->searchAll('logistics.file.document', [['file_id', '=', $id]], array_merge($f['logistics.file.document'], ['document_type_id']), 'file documents'),
                'reopenings' => $d->searchAll('logistics.file.reopening', [['file_id', '=', $id]], $f['logistics.file.reopening'], 'file re-openings'),
                'tasks' => $d->searchAll('clearance.task', [['file_id', '=', $id]], $f['clearance.task'], 'file waiting actions'),
                'labels' => [
                    'step' => $d->selectionLabels('clearance.turnaround', 'step'),
                    'expense_state' => $d->selectionLabels('logistics.expense', 'state'),
                    'payment_mode' => $d->selectionLabels('logistics.expense', 'payment_mode'),
                    'file_state' => $d->selectionLabels('logistics.file', 'state'),
                    'kind' => $d->selectionLabels('clearance.task', 'kind'),
                    'service_kind' => $d->selectionLabels('account.move.line', 'clearance_service_kind'),
                    'category' => $d->selectionLabels('account.move.line', 'clearance_category'),
                ],
            ];
        });
    }
}
