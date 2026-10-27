<?php

namespace App\Services\Clearance;

/**
 * Margin on débours (out-of-pocket expenses paid for clients and recharged).
 *
 *   incurred = disbursements paid out on the file (settled or later,
 *              cancelled ones aside, reversals deducted)
 *   billed   = the débours lines of the file's standing invoices
 *              (clearance_category = debours; service and Handling lines excluded),
 *              credit notes deducted
 *   margin   = billed − incurred   (> 0 gain, < 0 loss)
 *
 * Measured per file once it is invoiced (its first standing invoice dates
 * it), so a file still in progress — whose débours are naturally not yet
 * recharged — never reads as a loss. Read-only (ClearancePack / ClearanceData).
 */
final class DeboursMargin
{
    /** Per-file débours incurred and billed (all dates), plus first-invoice date and invoice numbers. */
    public static function perFile(ClearancePack $p): array
    {
        $out = [];
        foreach ($p->expenses() as $e) {
            if (!empty($e['is_legacy']) || ($e['state'] ?? '') === 'cancel' || !Rules::localDate($e['date_settled'] ?? null)) continue;
            $fid = ClearanceData::m2oId($e['file_id'] ?? false);
            if (!$fid) continue;
            $out[$fid]['incurred'] = ($out[$fid]['incurred'] ?? 0.0) + (float) ($e['amount'] ?? 0);
            $d = Rules::localDate($e['date_settled']);
            if (!isset($out[$fid]['last_paid']) || $d > $out[$fid]['last_paid']) $out[$fid]['last_paid'] = $d;
        }
        $inv = $p->invoicesById();
        $ok = $p->countedInvoiceIds();
        foreach ($inv as $mid => $m) {
            if (!isset($ok[$mid]) || ($m['move_type'] ?? '') !== 'out_invoice') continue;
            $fid = ClearanceData::m2oId($m['logistics_file_id'] ?? false);
            $d = Rules::localDate($m['invoice_date'] ?? null);
            if (!$fid || !$d) continue;
            if (!isset($out[$fid]['first_invoice']) || $d < $out[$fid]['first_invoice']) $out[$fid]['first_invoice'] = $d;
            $out[$fid]['invoices'][] = (string) ($m['name'] ?? '');
        }
        foreach ($p->invoiceLines() as $l) {
            // Handling is due back to the customer: outside the débours result (as in Odoo's debours_billed).
            if (($l['clearance_category'] ?? '') !== 'debours' || !empty($l['is_handling'])) continue;
            $mid = ClearanceData::m2oId($l['move_id'] ?? false);
            if (!$mid || !isset($ok[$mid])) continue;
            $fid = ClearanceData::m2oId($inv[$mid]['logistics_file_id'] ?? false);
            if (!$fid) continue;
            $sign = ($inv[$mid]['move_type'] ?? '') === 'out_refund' ? -1 : 1;
            $out[$fid]['billed'] = ($out[$fid]['billed'] ?? 0.0) + $sign * (float) ($l['price_subtotal'] ?? 0);
        }
        return $out;
    }

    /** The four profitability sandboxes for one year. */
    public static function forYear(int $year): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone(Rules::TZ)))->format('Y-m-d');
        $from = sprintf('%04d-01-01', $year);
        $to = min(sprintf('%04d-12-31', $year), $today);
        $d = ClearanceData::live(\App\Odoo\OdooFactory::fromConfig());
        $p = new ClearancePack($d, $from, $to, (string) $year, ['db' => '', 'user' => '']);
        return self::compute($p, $from, $to);
    }

    public static function compute(ClearancePack $p, string $from, string $to): array
    {
        $files = $p->filesById();
        $rows = []; $noCost = [];
        foreach (self::perFile($p) as $fid => $x) {
            $first = $x['first_invoice'] ?? null;
            if (!$first || $first < $from || $first > $to) continue;
            $f = $files[$fid] ?? [];
            if (($f['state'] ?? '') === 'imported') continue; // Teese history
            $inc = (float) ($x['incurred'] ?? 0); $bil = (float) ($x['billed'] ?? 0);
            if (abs($inc) < 0.5 && abs($bil) < 0.5) continue; // no débours on this file
            if (abs($inc) < 0.5) {
                // Débours recharged but none paid out in the application (paid
                // before go-live, outside it): no cost to measure against.
                $noCost[] = ['file' => (string) ($f['name'] ?? ('#' . $fid)), 'client' => ClearanceData::m2oName($f['partner_id'] ?? false, '') ?: '—',
                             'invoiced' => $first, 'billed' => $bil];
                continue;
            }
            $rows[] = [
                'id' => $fid, 'file' => (string) ($f['name'] ?? ('#' . $fid)),
                'client' => ClearanceData::m2oName($f['partner_id'] ?? false, '') ?: '—',
                'client_id' => ClearanceData::m2oId($f['partner_id'] ?? false) ?? 0,
                'invoiced' => $first, 'invoices' => implode(', ', array_unique($x['invoices'] ?? [])),
                'incurred' => $inc, 'billed' => $bil, 'margin' => $bil - $inc,
                'margin_pct' => $inc != 0.0 ? round(100 * ($bil - $inc) / abs($inc), 1) : null,
            ];
        }

        // Months of the year (by first-invoice month).
        $months = [];
        for ($m = new \DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->modify('+1 month')) {
            $months[$m->format('Y-m')] = ['label' => $m->format('M'), 'files' => 0, 'incurred' => 0.0, 'billed' => 0.0, 'margin' => 0.0,
                                          'gain' => 0.0, 'loss' => 0.0, 'loss_files' => 0];
        }
        $clients = [];
        foreach ($rows as $r) {
            $ym = substr($r['invoiced'], 0, 7);
            if (isset($months[$ym])) {
                $mm = &$months[$ym];
                $mm['files']++; $mm['incurred'] += $r['incurred']; $mm['billed'] += $r['billed']; $mm['margin'] += $r['margin'];
                if ($r['margin'] > 0.5) $mm['gain'] += $r['margin'];
                if ($r['margin'] < -0.5) { $mm['loss'] += $r['margin']; $mm['loss_files']++; }
                unset($mm);
            }
            $c = &$clients[$r['client_id'] . '|' . $r['client']];
            $c ??= ['client' => $r['client'], 'files' => 0, 'incurred' => 0.0, 'billed' => 0.0, 'margin' => 0.0,
                    'gain_files' => 0, 'loss_files' => 0, 'rows' => []];
            $c['files']++; $c['incurred'] += $r['incurred']; $c['billed'] += $r['billed']; $c['margin'] += $r['margin'];
            if ($r['margin'] > 0.5) $c['gain_files']++;
            if ($r['margin'] < -0.5) $c['loss_files']++;
            $c['rows'][] = $r;
            unset($c);
        }
        $cum = 0.0;
        foreach ($months as &$mm) {
            $mm['margin_pct'] = $mm['incurred'] != 0.0 ? round(100 * $mm['margin'] / abs($mm['incurred']), 1) : null;
            $cum += $mm['margin']; $mm['cumulative'] = $cum;
        }
        unset($mm);
        foreach ($clients as &$c) {
            $c['margin_pct'] = $c['incurred'] != 0.0 ? round(100 * $c['margin'] / abs($c['incurred']), 1) : null;
            usort($c['rows'], fn ($a, $b) => $a['margin'] <=> $b['margin']);
        }
        unset($c);
        $clients = array_values($clients);
        $profitable = array_values(array_filter($clients, fn ($c) => $c['margin'] > 0.5));
        $lossmaking = array_values(array_filter($clients, fn ($c) => $c['margin'] < -0.5));
        $even = array_values(array_filter($clients, fn ($c) => abs($c['margin']) <= 0.5));
        usort($profitable, fn ($a, $b) => $b['margin'] <=> $a['margin']);
        usort($lossmaking, fn ($a, $b) => $a['margin'] <=> $b['margin']);
        usort($even, fn ($a, $b) => $b['incurred'] <=> $a['incurred']);
        $lossFiles = array_values(array_filter($rows, fn ($r) => $r['margin'] < -0.5));
        usort($lossFiles, fn ($a, $b) => $a['margin'] <=> $b['margin']);

        $inc = array_sum(array_column($rows, 'incurred'));
        $bil = array_sum(array_column($rows, 'billed'));
        return [
            'from' => $from, 'to' => $to,
            'summary' => [
                'files' => count($rows), 'incurred' => $inc, 'billed' => $bil, 'margin' => $bil - $inc,
                'margin_pct' => $inc != 0.0 ? round(100 * ($bil - $inc) / abs($inc), 1) : null,
                'gain' => array_sum(array_map(fn ($r) => max(0, $r['margin']), $rows)),
                'loss' => array_sum(array_map(fn ($r) => min(0, $r['margin']), $rows)),
                'gain_files' => count(array_filter($rows, fn ($r) => $r['margin'] > 0.5)),
                'loss_files' => count($lossFiles),
                'clients_profitable' => count($profitable), 'clients_lossmaking' => count($lossmaking), 'clients_even' => count($even),
            ],
            'months' => $months,
            'profitable' => $profitable, 'lossmaking' => $lossmaking, 'even' => $even,
            'loss_files' => array_slice($lossFiles, 0, 25),
            'no_cost' => ['files' => count($noCost), 'billed' => array_sum(array_column($noCost, 'billed')),
                          'rows' => array_slice($noCost, 0, 50)],
        ];
    }
}
