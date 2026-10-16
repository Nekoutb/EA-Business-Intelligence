<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\Clearance\ClearanceData;
use App\Services\Clearance\ClearancePack;
use App\Services\Clearance\PackWorkbook;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Clearance management pack (R1–R11) — each report is its own module
 * (routes gated by module:clr_rN), read live from the tenant's Odoo 19
 * through read-only calls. Also serves the per-report and full-pack Excel
 * workbooks and the self-contained HTML pack (enabled reports only).
 */
class ClearanceReportsController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function show(Request $request, string $code)
    {
        $result = $this->run($request, [$code]);
        return view('clearance.report', $result + [
            'report' => $result['reports'][0] ?? null,
            'code' => $code,
            'active' => ClearancePack::REPORTS[$code][1],
            'periodLabel' => $result['periodLabel'],
        ]);
    }

    public function export(Request $request, string $code)
    {
        return $this->workbook($request, [$code], 'clearance-' . $code);
    }

    public function packXlsx(Request $request)
    {
        return $this->workbook($request, $this->enabledCodes(), 'clearance-pack');
    }

    public function packHtml(Request $request)
    {
        $result = $this->run($request, $this->enabledCodes());
        $html = view('clearance.pack', $result)->render();
        $name = 'clearance-pack-' . $this->ctx->tenant()->slug . '-' . $result['fromIso'] . '_' . $result['toIso'] . '.html';
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Codes of the reports this tenant has enabled, in order. */
    private function enabledCodes(): array
    {
        $codes = array_values(array_filter(array_keys(ClearancePack::REPORTS),
            fn ($c) => $this->ctx->moduleEnabled(ClearancePack::REPORTS[$c][1])));
        abort_if(!$codes, 403, 'No clearance report is activated for this workspace.');
        return $codes;
    }

    /**
     * Build the requested reports. Period: ?from=…&to=… (days), else
     * ?period=YYYY-MM, else the master
     * filter. Results cache per report + window; a schema failure (missing
     * Odoo field) stops everything with the missing names.
     */
    private function run(Request $request, array $codes): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala'));
        $period = current_period();
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('period', ''), $m)) {
            $period = ['type' => 'month', 'year' => (int) $m[1], 'month' => (int) $m[2]];
        }
        [$from, $to, $label] = ea_resolve_period($today, $period);
        $fromIso = $from->format('Y-m-d');
        $toIso = $to->format('Y-m-d');
        // Day filter: ?from=YYYY-MM-DD[&to=YYYY-MM-DD] (one day, or a range);
        // takes precedence over ?period. Clamped to today, swapped if reversed.
        $dayParams = [];
        $isDate = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false;
        $qFrom = $request->query('from');
        if ($isDate($qFrom)) {
            $qTo = $isDate($request->query('to')) ? $request->query('to') : $qFrom;
            if ($qTo < $qFrom) [$qFrom, $qTo] = [$qTo, $qFrom];
            $qTo = min($qTo, $today->format('Y-m-d'));
            $qFrom = min($qFrom, $qTo);
            [$fromIso, $toIso] = [$qFrom, $qTo];
            $label = $fromIso === $toIso ? 'Selected day' : 'Selected days';
            $dayParams = ['from' => $fromIso, 'to' => $toIso];
        }

        $source = [
            'db'   => (string) config_env('ODOO_DB', '—'),
            'user' => (string) config_env('ODOO_USERNAME', '—'),
        ];
        $overrides = [];
        foreach (array_keys((array) config('clearance_reports.thresholds')) as $k) {
            $v = config_env('CLEARANCE_' . strtoupper($k), null);
            if ($v !== null && $v !== '') $overrides[$k] = is_numeric($v) ? $v + 0 : $v;
        }

        $error = null;
        $reports = [];
        $validation = null;
        $extracted = $today->format('d/m/Y H:i');
        try {
            // 30-minute buckets: live enough for the daily queue report,
            // without re-reading Odoo on every click. ?refresh=1 forces it.
            $bucket = intdiv(time(), 1800) . ($request->boolean('refresh') ? '-' . time() : '');
            $key = 'clr_v1_' . md5(json_encode([$codes, $fromIso, $toIso, $overrides, $bucket]));
            $payload = ea_cached($key, function () use ($codes, $fromIso, $toIso, $label, $source, $overrides) {
                $d = ClearanceData::live(OdooFactory::fromConfig());
                $d->assertSchema();
                $pack = new ClearancePack($d, $fromIso, $toIso, $label, $source, $overrides);
                $validation = $pack->validation();
                $out = [];
                foreach ($codes as $c) {
                    [$cls] = ClearancePack::REPORTS[$c];
                    $out[] = (new $cls($pack, $c))->build();
                }
                return ['reports' => $out, 'validation' => $validation, 'queries' => $d->queryLog,
                        'warnings' => $d->warnings, 'extracted' => $pack->extractedAt];
            });
            $reports = $payload['reports'];
            $validation = $payload['validation'];
            $extracted = $payload['extracted'];
            foreach ($reports as &$r) $r['warnings'] = array_values(array_unique(array_merge($r['warnings'], $payload['warnings'])));
            unset($r);
            $queries = $payload['queries'];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $queries = [];
            \Illuminate\Support\Facades\Log::warning('clearance pack failed', ['error' => $error]);
        }

        return [
            'reports' => $reports, 'validation' => $validation, 'error' => $error, 'queries' => $queries,
            'periodLabel' => $label, 'fromIso' => $fromIso, 'toIso' => $toIso,
            'meta' => $source + ['extracted' => $extracted, 'company' => $this->ctx->tenant()->name],
            'enabled' => array_values(array_filter(array_keys(ClearancePack::REPORTS),
                fn ($c) => $this->ctx->moduleEnabled(ClearancePack::REPORTS[$c][1]))),
            'periodParam' => $dayParams ? '' : (string) $request->query('period', ''),
            'dayParams' => $dayParams,
        ];
    }

    private function workbook(Request $request, array $codes, string $base)
    {
        $result = $this->run($request, $codes);
        abort_if($result['error'] !== null, 500, 'Clearance pack failed: ' . $result['error']);
        $tmp = tempnam(sys_get_temp_dir(), 'clr') . '.xlsx';
        PackWorkbook::write($result['reports'], $tmp, $result['meta']);
        $name = $base . '-' . $this->ctx->tenant()->slug . '-' . $result['fromIso'] . '_' . $result['toIso'] . '.xlsx';
        return response()->download($tmp, $name, ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend(true);
    }
}
