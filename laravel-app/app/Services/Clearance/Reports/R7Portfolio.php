<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R7 — Per client over 12 months: billing, fees, due, overdue, unbilled, exposure, VAT (R-n). */
class R7Portfolio extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $to = $p->to;
        $from = (new \DateTimeImmutable(substr($to, 0, 7) . '-01'))->modify('-11 months')->format('Y-m-d');
        $inv = $p->invoicesById();
        $counted = $p->countedInvoiceIds();
        $limit = (float) $p->thresholds['exposure_limit_xaf'];
        $files = $p->filesById();

        $c = [];
        $row = function (int $pid, string $name) use (&$c): int {
            $c[$pid] ??= ['client' => $name, 'files' => 0, 'ttc' => 0.0, 'fees' => 0.0, 'vat' => 0.0, 'due' => 0.0,
                                 'overdue' => 0.0, 'paydays' => null, 'unbilled' => 0.0, '_pid' => $pid];
            return $pid;
        };

        foreach ($p->files() as $f) {
            if (Rules::isCountedFile($f) && Rules::inPeriod($f['date_opened'] ?? null, $from, $to) && ($pid = $this->id($f['partner_id'] ?? false))) {
                $c[$row($pid, $this->name($f['partner_id']))]['files']++;
            }
        }
        foreach ($inv as $id => $m) {
            if (!isset($counted[$id]) || !($pid = $this->id($m['partner_id'] ?? false))) continue;
            $row($pid, $this->name($m['partner_id']));
            $r = &$c[$pid];
            $sign = $m['move_type'] === 'out_refund' ? -1 : 1;
            if (Rules::inPeriod($m['invoice_date'] ?? null, $from, $to)) {
                $r['ttc'] += $sign * (float) $m['amount_total'];
                $r['vat'] += $sign * (float) $m['amount_tax'];
            }
            if ($m['move_type'] === 'out_invoice') {
                $res = (float) $m['amount_residual'];
                $r['due'] += $res;
                $late = Rules::ageDays($m['invoice_date_due'] ?? null, $p->asOf);
                if ($res > 0 && $late !== null && $late > 30) $r['overdue'] += $res;
            }
            unset($r);
        }
        foreach (self::groupBy($p->linesInWindow($from, $to), fn ($l) => $this->id($inv[$this->id($l['move_id'])]['partner_id'] ?? false) ?? 0) as $pid => $ls) {
            if (!$pid) continue;
            $m = $inv[$this->id($ls[0]['move_id'])];
            $c[$row($pid, $this->name($m['partner_id']))]['fees'] += Rules::fees($ls, $inv, $counted)['fees'];
        }
        foreach ($p->unbilled() as $e) {
            $fid = $this->id($e['file_id'] ?? false);
            if ($fid && isset($files[$fid]) && ($pid = $this->id($files[$fid]['partner_id'] ?? false))) {
                $c[$row($pid, $this->name($files[$fid]['partner_id']))]['unbilled'] += (float) $e['amount'];
            }
        }

        $partners = $p->partnersById(array_keys($c));
        $feesAll = $feesExempt = 0.0;
        foreach ($c as $pid => &$r) {
            $r['exempt'] = !empty($partners[$pid]['clearance_vat_exempt']) ? 'Yes' : 'No';
            $r['exposure'] = $r['due'] + $r['unbilled'];
            $r['_flag'] = $r['exposure'] > $limit ? 'red' : ($r['overdue'] > 0 ? 'amber' : null);
            $feesAll += $r['fees'];
            if ($r['exempt'] === 'Yes') $feesExempt += $r['fees'];
        }
        unset($r);
        $rows = array_values(array_filter($c, fn ($r) => $r['files'] || $r['ttc'] || $r['due'] || $r['unbilled']));
        usort($rows, fn ($a, $b) => $b['exposure'] <=> $a['exposure']);

        // Average days to full payment needs the payment (reconciliation)
        // date, which is not among the permitted account.move fields.
        $this->warnings[] = 'Average days from invoice to full payment is not computed: the payment date is not among the permitted fields '
            . '(it needs the reconciled payment date of each invoice). The "> 60 days" flag therefore cannot apply.';

        $over = array_filter($rows, fn ($r) => $r['exposure'] > $limit);
        return [
            'period' => '12 months ' . Rules::fdate($from) . ' – ' . Rules::fdate($to),
            'decide' => $rows
                ? ($over ? count($over) . ' client' . (count($over) > 1 ? 's exceed' : ' exceeds') . ' the ' . Rules::xaf($limit) . ' XAF exposure limit — start with ' . $rows[0]['client'] . '.'
                         : 'Largest exposure: ' . $rows[0]['client'] . ' (' . Rules::xaf($rows[0]['exposure']) . ' XAF); no client above the limit.')
                : 'No client activity in the last 12 months.',
            'kpis' => [
                $this->kpi('Amount due', self::sum($rows, 'due'), 'xaf'),
                $this->kpi('Overdue > 30 days', self::sum($rows, 'overdue'), 'xaf', null, self::sum($rows, 'overdue') > 0 ? 'amber' : 'green'),
                $this->kpi('Exposure (due + unbilled)', self::sum($rows, 'exposure'), 'xaf'),
                $this->kpi('VAT billed', self::sum($rows, 'vat'), 'xaf'),
                $this->kpi('Fees to VAT-exempt clients', Rules::pct($feesExempt, $feesAll), 'pct'),
            ],
            'sections' => [
                $this->section('Clients by exposure', [
                    ['client', 'Client', 'text'], ['files', 'Files', 'int'], ['ttc', 'Billed TTC', 'xaf'], ['fees', 'Fees HT', 'xaf'],
                    ['vat', 'VAT billed', 'xaf'], ['exempt', 'VAT exempt', 'text'], ['due', 'Due', 'xaf'], ['overdue', 'Overdue > 30 d', 'xaf'],
                    ['paydays', 'Avg days to pay', 'days'], ['unbilled', 'Unbilled', 'xaf'], ['exposure', 'Exposure', 'xaf'],
                ], $rows, 'No client activity in the last 12 months.', null, 'Red: exposure above ' . Rules::xaf($limit) . ' XAF. Amber: amounts overdue more than 30 days.'),
            ],
        ];
    }
}
