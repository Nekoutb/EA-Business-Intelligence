<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Services\ClearanceIntel\Report;
use App\Services\ClearanceIntel\Targets;
use Illuminate\Http\Request;

/**
 * Clearance Intelligence — management reporting on the clearance
 * application (Odoo 19 + elite_clearance). One page per module
 * (ci_overview … ci_library), all computed from one cached payload.
 * Read-only towards Odoo.
 */
class ClearanceIntelController extends Controller
{
    public const PAGES = [
        'overview'      => ['ci_overview', 'Executive overview'],
        'activity'      => ['ci_activity', 'Operational activity'],
        'productivity'  => ['ci_productivity', 'Productivity'],
        'efficiency'    => ['ci_efficiency', 'Efficiency & delays'],
        'profitability' => ['ci_profitability', 'Profitability'],
        'leakage'       => ['ci_leakage', 'Leakages & controls'],
        'trends'        => ['ci_trends', 'Trends'],
        'forecast'      => ['ci_forecast', 'Forecasts'],
        'files'         => ['ci_files', 'File drilldown'],
        'kpis'          => ['ci_library', 'KPI library & targets'],
    ];

    /** Warm-worker entry (config/warm.php): the overview for the default period. */
    public function index(Request $request)
    {
        return $this->page($request, 'overview');
    }

    public function page(Request $request, string $page)
    {
        abort_unless(isset(self::PAGES[$page]), 404);
        [$from, $to, $label, $filter] = $this->window($request);
        $data = null; $error = null;
        try {
            $data = Report::build($from, $to, $request->boolean('refresh'));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            \Illuminate\Support\Facades\Log::warning('clearance intelligence failed', ['error' => $error]);
        }
        return view('clearance-intel.' . $page, [
            'page' => $page, 'title' => self::PAGES[$page][1], 'data' => $data, 'error' => $error,
            'periodLabel' => $label, 'filter' => $filter, 'canEdit' => $this->canEditTargets(),
        ]);
    }

    public function file(Request $request, int $id)
    {
        [$from, $to, $label, $filter] = $this->window($request);
        $error = null; $rec = null; $fact = null;
        try {
            $rec = Report::file($id);
            $data = Report::build($from, $to);
            foreach ($data['files'] as $f) if ((int) $f['id'] === $id) { $fact = $f; break; }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        abort_if(!$error && empty($rec['file']), 404);
        return view('clearance-intel.file', [
            'page' => 'files', 'title' => 'File ' . ($rec['file']['name'] ?? $id), 'rec' => $rec, 'fact' => $fact,
            'error' => $error, 'periodLabel' => $label, 'filter' => $filter, 'data' => null, 'canEdit' => false,
        ]);
    }

    public function saveTargets(Request $request)
    {
        abort_unless($this->canEditTargets(), 403, 'Only an administrator can change targets.');
        $out = [];
        foreach ((array) config('clearance_intel.kpis') as $k => $def) {
            foreach (['target', 'trigger'] as $f) {
                $v = $request->input("kpi.{$k}.{$f}");
                if ($v !== null && $v !== '' && is_numeric(str_replace([' ', ','], ['', '.'], (string) $v))) {
                    $out[$k][$f] = (float) str_replace([' ', ','], ['', '.'], (string) $v);
                } elseif ($v === '') {
                    $out[$k][$f] = null;
                }
            }
        }
        foreach (array_keys((array) config('clearance_intel.triggers')) as $t) {
            $v = $request->input("trigger.{$t}");
            if ($v !== null && $v !== '' && ctype_digit((string) $v)) $out['t:' . $t] = (int) $v;
        }
        \Illuminate\Support\Facades\DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => tenant()->id, 'key' => 'CLRI_TARGETS'],
            ['value' => json_encode($out), 'updated_at' => now(), 'created_at' => now()]
        );
        \App\Models\AuditLog::record('clearance_intel.targets.update', ['keys' => count($out)]);
        return redirect(tenant_url('/clearance/kpis'))->with('flash', ['type' => 'success', 'msg' => 'Targets saved. Every page now measures against them.']);
    }

    private function canEditTargets(): bool
    {
        $u = auth()->user();
        return $u && ($u->isSuperAdmin() || $u->isTenantAdmin());
    }

    /** [from, to, label, filter-query]: ?from=&to= (days), ?period=YYYY-MM, else the master filter. */
    private function window(Request $request): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala'));
        $isDate = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false;
        $qFrom = $request->query('from');
        if ($isDate($qFrom)) {
            $qTo = $isDate($request->query('to')) ? $request->query('to') : $qFrom;
            if ($qTo < $qFrom) [$qFrom, $qTo] = [$qTo, $qFrom];
            $qTo = min($qTo, $today->format('Y-m-d'));
            $qFrom = min($qFrom, $qTo);
            $label = (new \DateTimeImmutable($qFrom))->format('d/m/Y') . ($qFrom === $qTo ? '' : ' – ' . (new \DateTimeImmutable($qTo))->format('d/m/Y'));
            return [$qFrom, $qTo, $label, ['from' => $qFrom, 'to' => $qTo]];
        }
        $period = current_period();
        $filter = [];
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('period', ''), $m)) {
            $period = ['type' => 'month', 'year' => (int) $m[1], 'month' => (int) $m[2]];
            $filter = ['period' => $m[0]];
        }
        [$from, $to, $label] = ea_resolve_period($today, $period);
        return [$from->format('Y-m-d'), $to->format('Y-m-d'), $label, $filter];
    }
}
