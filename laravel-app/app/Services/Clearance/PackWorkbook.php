<?php

namespace App\Services\Clearance;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel workbook for the pack: one sheet per report, with the decision
 * line, KPIs, every table (raw rows, so management can filter), the
 * reconciliations and the warnings. Amounts are written as numbers
 * with a space-grouped XAF format.
 */
class PackWorkbook
{
    public static function write(array $reports, string $path, array $meta): void
    {
        $title = (new Style())->withFontBold(true)->withFontSize(13);
        $muted = (new Style())->withFontSize(9)->withFontColor('777777');
        $head  = (new Style())->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor('3E5A44');
        $bold  = (new Style())->withFontBold(true);
        $warn  = (new Style())->withFontColor('B00020')->withFontBold(true);
        $money = (new Style())->withFormat('#,##0;-#,##0');
        $pct   = (new Style())->withFormat('0.0');

        $w = new Writer();
        $w->openToFile($path);
        $first = true;
        foreach ($reports as $r) {
            $sheet = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $r['code'] . ' ' . $r['title']), 0, 31));

            $row = fn (array $vals, ?Style $s = null) => $w->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v, $s), $vals)));
            $row([$r['code'] . ' — ' . $r['title']], $title);
            $row([$r['period'] . ' · ' . $r['cadence']], $muted);
            $row(['What to decide: ' . $r['decide']], $bold);
            foreach ($r['warnings'] as $wn) $row([$wn], $warn);
            $row(['']);

            if ($r['kpis']) {
                $row(['Indicator', 'Value', 'Previous period'], $head);
                foreach ($r['kpis'] as $k) {
                    $w->addRow(new Row([
                        Cell::fromValue($k['label'] . ($k['sub'] ? " ({$k['sub']})" : '')),
                        Cell::fromValue(self::num($k['value'], $k['fmt']), $k['fmt'] === 'pct' ? $pct : $money),
                        Cell::fromValue(self::num($k['prev'], $k['fmt']), $k['fmt'] === 'pct' ? $pct : $money),
                    ]));
                }
                $row(['']);
            }

            foreach ($r['sections'] as $s) {
                $row([$s['title']], $bold);
                $row(array_map(fn ($c) => $c[1], $s['columns']), $head);
                $rows = $s['rows'];
                if ($s['total']) $rows[] = $s['total'];
                if (!$rows) { $row([$s['empty']], $muted); }
                foreach ($rows as $data) {
                    $w->addRow(new Row(array_map(function ($c) use ($data, $money, $pct) {
                        [$key, , $fmt] = $c;
                        $v = $data[$key] ?? null;
                        return match ($fmt) {
                            'xaf', 'int', 'days' => Cell::fromValue(self::num($v, $fmt), $money),
                            'pct', 'hours'       => Cell::fromValue(self::num($v, $fmt), $pct),
                            'date'               => Cell::fromValue($v ? Rules::fdate($v) : ''),
                            default              => Cell::fromValue(is_scalar($v) ? (string) $v : ''),
                        };
                    }, $s['columns'])));
                }
                if ($s['note']) $row([$s['note']], $muted);
                $row(['']);
            }

            if ($r['recon']) {
                $row(['Reconciliation', 'Report', 'Ledger', 'Difference', 'Status'], $head);
                foreach ($r['recon'] as $rc) {
                    $w->addRow(new Row([
                        Cell::fromValue($rc['label']), Cell::fromValue((float) $rc['report'], $money),
                        Cell::fromValue((float) $rc['ledger'], $money), Cell::fromValue((float) $rc['difference'], $money),
                        Cell::fromValue($rc['ok'] ? 'OK' : 'WARNING', $rc['ok'] ? null : $warn),
                    ]));
                }
                $row(['']);
            }
            $row(['Source: Odoo ' . $meta['db'] . ', extracted ' . $meta['extracted'] . ', by ' . $meta['user']], $muted);
        }
        $w->close();
    }

    private static function num(mixed $v, string $fmt): float|int|string
    {
        if ($v === null || $v === '' || $v === false) return '';
        if (!is_numeric($v)) return (string) $v;
        return in_array($fmt, ['int', 'days'], true) ? (int) $v : round((float) $v, $fmt === 'xaf' ? 0 : 1);
    }
}
