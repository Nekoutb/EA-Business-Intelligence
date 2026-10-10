<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\ClearanceData;
use App\Services\Clearance\ClearancePack;
use App\Services\Clearance\Rules;

/**
 * Base for R1–R11. Every report returns the same shape so the web page,
 * the self-contained HTML pack and the Excel workbook render from one
 * structure:
 *
 *   code, title, cadence, period, decide,
 *   kpis     [label, value, fmt, prev?, flag?]
 *   charts   [type line|bar, title, labels[], values[], fmt]
 *   sections [title, columns[[key,label,fmt]], rows[], total?, empty, note?]
 *   recon    [label, report, ledger, difference, ok]
 *   warnings string[]
 *
 * Column formats: text · int · xaf · pct · date · days · hours.
 * A row's '_flag' (red|amber|green) colours it.
 */
abstract class Report
{
    protected array $warnings = [];

    public function __construct(protected ClearancePack $p, protected string $code) {}

    abstract protected function body(): array;

    public function build(): array
    {
        [, , $title, $cadence] = ClearancePack::REPORTS[$this->code];
        $b = $this->body();
        return array_merge([
            'code' => strtoupper($this->code), 'title' => $title, 'cadence' => $cadence,
            'period' => $this->periodText(), 'decide' => '', 'kpis' => [], 'charts' => [],
            'sections' => [], 'recon' => [],
        ], $b, ['warnings' => array_values(array_unique(array_merge($this->warnings, $b['warnings'] ?? [])))]);
    }

    /** Snapshot reports describe "now"; event reports describe the window. */
    protected function periodText(): string
    {
        return $this->p->periodLabel . ' (' . Rules::fdate($this->p->from) . ' – ' . Rules::fdate($this->p->to) . ')';
    }

    protected function asOfText(): string
    {
        return 'Position at ' . Rules::fdate($this->p->asOf);
    }

    protected function section(string $title, array $columns, array $rows, string $empty, ?array $total = null, ?string $note = null): array
    {
        return compact('title', 'columns', 'rows', 'empty', 'total', 'note');
    }

    protected function kpi(string $label, float|int|null $value, string $fmt, float|int|null $prev = null, ?string $flag = null, ?string $sub = null): array
    {
        return compact('label', 'value', 'fmt', 'prev', 'flag', 'sub');
    }

    protected function reconRow(array $r, string $label): array
    {
        if (!$r['ok']) {
            $this->warnings[] = "WARNING — {$label}: report " . Rules::xaf($r['report']) . ' vs ledger ' . Rules::xaf($r['ledger'])
                . ' (difference ' . Rules::xaf($r['difference']) . ' XAF).';
        }
        return ['label' => $label] + $r;
    }

    protected function name(mixed $m2o): string
    {
        return ClearanceData::m2oName($m2o);
    }

    protected function id(mixed $m2o): ?int
    {
        return ClearanceData::m2oId($m2o);
    }

    /** Group rows by a key and reduce each group. */
    protected static function groupBy(array $rows, callable $key): array
    {
        $g = [];
        foreach ($rows as $r) $g[$key($r)][] = $r;
        return $g;
    }

    protected static function sum(array $rows, string $field): float
    {
        return array_sum(array_map(fn ($r) => (float) ($r[$field] ?? 0), $rows));
    }
}
