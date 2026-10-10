<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/**
 * R6 — Net fees by service type, client and file, from invoice lines
 * (never analytic balances — R-e), plus every below-cost recharge line
 * with its comment (R-o) and approvers.
 */
class R6Profitability extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $inv = $p->invoicesById();
        $files = $p->filesById();
        $lines = $p->linesInWindow($p->from, $p->to);

        $byFile = self::groupBy($lines, fn ($l) => $this->id($inv[$this->id($l['move_id'])]['logistics_file_id'] ?? false) ?? 0);
        $fileRows = [];
        foreach ($byFile as $fid => $ls) {
            $f = Rules::fees($ls, $inv, $p->countedInvoiceIds());
            $file = $files[$fid] ?? null;
            $fileRows[] = [
                'file' => $file['name'] ?? '(no file)', 'client' => $file ? $this->name($file['partner_id'] ?? false) : '—',
                'service' => $file ? $p->serviceCode($file) : '—',
                'commission' => $f['commission'], 'had' => $f['customs_fee'], 'fee_other' => $f['file_fee'] + $f['other'],
                'adjustment' => $f['adjustment'], 'net' => $f['net'],
                '_flag' => $f['adjustment'] < 0 ? 'amber' : null,
            ];
        }

        $roll = function (string $key) use ($fileRows) {
            $out = [];
            foreach (self::groupBy($fileRows, fn ($r) => $r[$key]) as $k => $rs) {
                $n = count($rs);
                $net = self::sum($rs, 'net');
                $out[] = [$key => $k, 'files' => $n, 'commission' => self::sum($rs, 'commission'), 'had' => self::sum($rs, 'had'),
                          'fee_other' => self::sum($rs, 'fee_other'), 'adjustment' => self::sum($rs, 'adjustment'),
                          'net' => $net, 'per_file' => $n ? $net / $n : null];
            }
            usort($out, fn ($a, $b) => $b['net'] <=> $a['net']);
            return $out;
        };
        $moneyCols = [['files', 'Files billed', 'int'], ['commission', 'Commission', 'xaf'], ['had', 'HAD - Honoraires Agréés en Douane', 'xaf'],
                      ['fee_other', 'File fee + other', 'xaf'], ['adjustment', 'Recharge adjustment', 'xaf'], ['net', 'Net fees', 'xaf'], ['per_file', 'Net per file', 'xaf']];

        $sorted = $fileRows;
        usort($sorted, fn ($a, $b) => $b['net'] <=> $a['net']);
        $fileCols = [['file', 'File', 'text'], ['client', 'Client', 'text'], ['service', 'Service', 'text'],
                     ['commission', 'Commission', 'xaf'], ['had', 'HAD', 'xaf'], ['fee_other', 'File fee + other', 'xaf'],
                     ['adjustment', 'Adjustment', 'xaf'], ['net', 'Net fees', 'xaf']];

        // Below-cost recharge lines on the files billed in this period (R-o).
        $billedFileIds = array_flip(array_keys($byFile));
        $below = [];
        foreach ($p->expenses() as $e) {
            $fid = $this->id($e['file_id'] ?? false);
            if (!$fid || !isset($billedFileIds[$fid]) || ($e['state'] ?? '') === 'cancel') continue;
            $re = $e['recharge_amount'] ?? false;
            if ($re === false || $re === null || (float) $re >= (float) $e['amount']) continue;
            $file = $files[$fid] ?? [];
            $below[] = [
                'file' => $file['name'] ?? '—', 'exp' => $e['name'], 'category' => $this->name($e['category_id'] ?? false),
                'cost' => (float) $e['amount'], 'charged' => (float) $re, 'variance' => (float) $re - (float) $e['amount'],
                'comment' => trim((string) ($e['recharge_comment'] ?: '')) ?: '(no line comment)',
                'note' => trim((string) ($file['recharge_reason'] ?? '')) ?: '—',
                'ops' => $this->name($file['recharge_ops_approved_by_id'] ?? false),
                'gm' => $this->name($file['recharge_gm_approved_by_id'] ?? false),
                '_flag' => 'amber',
            ];
        }

        $tot = Rules::fees($lines, $inv, $p->countedInvoiceIds());
        if (!$lines) $this->warnings[] = 'No standing invoice lines dated in this period — profitability tables are empty.';
        return [
            'decide' => $fileRows
                ? 'Net fees ' . Rules::xaf($tot['net']) . ' XAF on ' . count($fileRows) . ' files; recharge adjustments ' . Rules::xaf($tot['adjustment'])
                  . ($below ? ' — review the ' . count($below) . ' below-cost lines.' : '.')
                : 'No file was billed in this period.',
            'kpis' => [
                $this->kpi('Fees HT', $tot['fees'], 'xaf'),
                $this->kpi('Recharge adjustment', $tot['adjustment'], 'xaf', null, $tot['adjustment'] < 0 ? 'amber' : null),
                $this->kpi('Net fees', $tot['net'], 'xaf'),
                $this->kpi('Net per file', $fileRows ? $tot['net'] / count($fileRows) : null, 'xaf'),
            ],
            'sections' => [
                $this->section('By service type', array_merge([['service', 'Service', 'text']], $moneyCols), $roll('service'), 'No billed files.'),
                $this->section('By client', array_merge([['client', 'Client', 'text']], $moneyCols), $roll('client'), 'No billed files.'),
                $this->section('Top 10 files by net fees', $fileCols, array_slice($sorted, 0, 10), 'No billed files.'),
                $this->section('Bottom 10 files by net fees', $fileCols, array_slice(array_reverse($sorted), 0, 10), 'No billed files.'),
                $this->section('Below-cost recharge lines', [
                    ['file', 'File', 'text'], ['exp', 'Disbursement', 'text'], ['category', 'Category', 'text'],
                    ['cost', 'Cost', 'xaf'], ['charged', 'Charged', 'xaf'], ['variance', 'Variance', 'xaf'],
                    ['comment', 'Line comment', 'text'], ['note', 'File note', 'text'], ['ops', 'Approved (Ops)', 'text'], ['gm', 'Approved (GM)', 'text'],
                ], $below, 'No disbursement was charged below cost on the files billed in this period.'),
            ],
        ];
    }
}
