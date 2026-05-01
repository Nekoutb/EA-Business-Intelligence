<?php
declare(strict_types=1);

namespace App\Services;

use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\CSV\Reader as CsvReader;

/**
 * Parse an uploaded budget Excel/CSV file into the
 *   {line_key: {1..12 => XAF amount}}  shape that BudgetService stores.
 *
 * Expected shape of the uploaded file (headerless variation tolerated):
 *
 *   Row 1 (header): | P&L Line | Jan | Feb | Mar | ... | Dec | (Total optional)
 *   Row 2..N      : | <line label> | 12 numeric cells |
 *
 * Values may be expressed in MILLIONS (e.g. "45" = 45,000,000 XAF) or
 * in absolute XAF (e.g. "45000000"). The parser auto-detects: if the
 * largest absolute value across the file is < 1,000,000, multiplies
 * everything by 1,000,000. This matches the user's display unit
 * (millions, no decimals) but accepts raw XAF without complaint.
 *
 * Cost lines are accepted in either presentation — negative-with-sign
 * (e.g. "-25" or "(25)") or positive-with-implied-subtraction (e.g.
 * "25" with the line label clearly being a cost). The parser inspects
 * the line label first to decide; if a cost line has all-positive
 * values it flips them to negative.
 *
 * Line label mapping is fuzzy — case-insensitive, whitespace and
 * punctuation tolerant — so "REVENUE", "Revenue ", "Total Revenue"
 * all resolve to the 'revenue' line key.
 */
final class BudgetParser
{
    /**
     * Patterns used to map free-text labels in the uploaded file to
     * one of the canonical BudgetService::LINE_KEYS. First match wins.
     */
    private const LABEL_PATTERNS = [
        'revenue'       => ['revenue', 'sales', 'turnover', 'chiffre d\'affaires', 'chiffre d affaires', 'ca'],
        'direct_costs'  => ['direct operating cost', 'cogs', 'cost of sales', 'cost of revenue', 'cout direct', 'coût direct', 'achat'],
        'opex'          => ['operating expense', 'opex', 'sg&a', 'sga', 'overhead', 'frais general', 'autres charges'],
        'depreciation'  => ['depreciation', 'amorti', 'amortisation', 'amortization', 'dotation'],
        'net_financial' => ['financial result', 'net financial', 'finance', 'resultat financier', 'résultat financier'],
        'income_tax'    => ['income tax', 'tax', 'impot', 'impôt', 'corporate tax'],
    ];

    /** Cost-direction lines — should normalise to negative values on display. */
    private const COST_LINES = ['direct_costs', 'opex', 'depreciation', 'income_tax'];

    /**
     * @return array{
     *   data: array<string, array<int, float>>,
     *   warnings: array<int, string>
     * }
     *
     * @throws \RuntimeException on unparseable input
     */
    public function parse(string $filePath, string $extension = 'xlsx'): array
    {
        $rows = $this->readRows($filePath, $extension);
        if (count($rows) === 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        // Detect header row — first row whose cells include >= 6 month
        // names (Jan-Dec or Jan, Feb, ...).
        $headerRow = null;
        $monthColIdx = []; // [month_number => column_index]
        foreach ($rows as $i => $row) {
            $monthColIdx = $this->detectMonthColumns($row);
            if (count($monthColIdx) >= 6) {
                $headerRow = $i;
                break;
            }
        }
        if ($headerRow === null || count($monthColIdx) < 6) {
            throw new \RuntimeException('Could not find a header row with month columns (Jan..Dec). Make sure the first row of your file lists "P&L Line, Jan, Feb, ..., Dec".');
        }

        $data = [];
        foreach (\App\Services\BudgetService::LINE_KEYS as $k) {
            $data[$k] = array_fill(1, 12, 0.0);
        }

        $warnings = [];
        $maxAbs = 0.0;

        // Walk data rows below the header; first column = label.
        for ($i = $headerRow + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $label = trim((string) ($row[0] ?? ''));
            if ($label === '') continue;
            $key = $this->matchLineKey($label);
            if ($key === null) {
                $warnings[] = "Row " . ($i + 1) . ": label \"" . $label . "\" did not match any known P&L line — skipped.";
                continue;
            }

            for ($m = 1; $m <= 12; $m++) {
                if (!isset($monthColIdx[$m])) continue;
                $col = $monthColIdx[$m];
                $raw = $row[$col] ?? null;
                $val = $this->parseNumber($raw);
                if ($val === null) continue;
                $data[$key][$m] = $val;
                $maxAbs = max($maxAbs, abs($val));
            }
        }

        // Auto-detect millions vs raw XAF: anything with all values < 1M
        // is almost certainly entered in millions — scale up.
        if ($maxAbs > 0 && $maxAbs < 1_000_000) {
            foreach ($data as $k => $v) {
                foreach ($v as $m => $amt) $data[$k][$m] = $amt * 1_000_000.0;
            }
            $warnings[] = 'Values appear to be in MILLIONS XAF — multiplied by 1,000,000 on import.';
        }

        // Enforce sign convention on cost lines: if a cost line has all
        // positive values, flip them. Warn so the user knows.
        foreach (self::COST_LINES as $costKey) {
            $vec = $data[$costKey];
            $hasPos = false; $hasNeg = false;
            foreach ($vec as $v) { if ($v > 0) $hasPos = true; if ($v < 0) $hasNeg = true; }
            if ($hasPos && !$hasNeg) {
                foreach ($vec as $m => $v) $data[$costKey][$m] = -abs($v);
                $warnings[] = 'Line "' . \App\Services\BudgetService::LINE_LABELS[$costKey] . '": all-positive values flipped to negative (cost line).';
            }
        }

        return ['data' => $data, 'warnings' => $warnings];
    }

    /**
     * Read rows from the uploaded file as arrays of scalar cell values.
     *
     * openspout v5's Row class doesn't expose a getCells() iterator, so
     * we go via toArray() (which calls Cell::getValue() per cell). For
     * FormulaCell that returns the FORMULA TEXT, not the cached
     * computed value — fine for plain budget files where users enter
     * numbers directly, but breaks for spreadsheets with =SUM() in the
     * total column. Reflection on the protected $cells lets us call
     * getComputedValue() on formula cells and getValue() on the rest.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readRows(string $filePath, string $extension): array
    {
        $rows = [];
        $reader = strtolower($extension) === 'csv' ? new CsvReader() : new XlsxReader();
        try {
            $reader->open($filePath);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $this->extractRowValues($row);
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
        return $rows;
    }

    /**
     * Pull every cell out of a Row, returning the COMPUTED value for
     * formula cells and the raw value for everything else.
     *
     * @return array<int, mixed>
     */
    private function extractRowValues(\OpenSpout\Common\Entity\Row $row): array
    {
        // Row::cells is protected; reach in via reflection. Cached so
        // we don't reflect every row.
        static $cellsProp = null;
        if ($cellsProp === null) {
            $rc = new \ReflectionClass(\OpenSpout\Common\Entity\Row::class);
            if ($rc->hasProperty('cells')) {
                $cellsProp = $rc->getProperty('cells');
                $cellsProp->setAccessible(true);
            }
        }

        $out = [];
        if ($cellsProp !== null) {
            $cells = $cellsProp->getValue($row);
            foreach ($cells as $cell) {
                if ($cell instanceof EmptyCell) { $out[] = null; continue; }
                if ($cell instanceof FormulaCell) {
                    $computed = $cell->getComputedValue();
                    $out[] = $computed ?? $cell->getValue();
                    continue;
                }
                $out[] = method_exists($cell, 'getValue') ? $cell->getValue() : null;
            }
            return $out;
        }
        // Fallback: toArray() — works for non-formula files
        return $row->toArray();
    }

    /**
     * Identify which column is which month. Looks for "Jan", "January",
     * "01" etc. in each cell.
     *
     * @return array<int, int>  month_number => column_index
     */
    private function detectMonthColumns(array $row): array
    {
        $months = [
            'jan' => 1, 'january' => 1,
            'feb' => 2, 'february' => 2,
            'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'may' => 5,
            'jun' => 6, 'june' => 6,
            'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10,
            'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12,
        ];
        $out = [];
        foreach ($row as $idx => $cell) {
            $s = strtolower(trim((string) $cell));
            $s = preg_replace('/[^a-z]/', '', $s);
            if ($s !== '' && isset($months[$s])) {
                $out[$months[$s]] = $idx;
            }
        }
        return $out;
    }

    private function matchLineKey(string $label): ?string
    {
        $norm = strtolower($label);
        $norm = preg_replace('/[^a-z0-9& ]/i', ' ', $norm) ?? $norm;
        $norm = trim(preg_replace('/\s+/', ' ', $norm) ?? $norm);
        foreach (self::LABEL_PATTERNS as $key => $patterns) {
            foreach ($patterns as $p) {
                if (str_contains($norm, strtolower($p))) return $key;
            }
        }
        return null;
    }

    private function parseNumber(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) return (float) $raw;
        $s = trim((string) $raw);
        if ($s === '' || $s === '—' || $s === '-' || strtolower($s) === 'n/a') return null;

        // Parens negative: "(25)" -> -25
        $negParens = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $negParens = true;
            $s = substr($s, 1, -1);
        }
        // Strip thousand separators (commas, spaces) but keep decimal dot.
        $s = preg_replace('/[\s,]/', '', $s) ?? $s;
        // Handle French decimal comma if it's the only non-digit
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        $val = (float) $s;
        return $negParens ? -$val : $val;
    }
}
