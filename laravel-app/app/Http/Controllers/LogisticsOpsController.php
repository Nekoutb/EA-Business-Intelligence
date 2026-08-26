<?php

namespace App\Http\Controllers;

use App\Services\LogisticsOpsService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Logistics Operations — dossier-based sector pages (Dossiers, Workflow,
 * Volumes). Warehouse-only reads; period from the shared master filter.
 */
class LogisticsOpsController extends Controller
{
    private function ctx(Request $request): array
    {
        $today  = new \DateTimeImmutable();
        $period = [
            'type'  => $request->query('period', 'ytd'),
            'year'  => (int) $request->query('year',  $today->format('Y')),
            'month' => (int) $request->query('month', $today->format('m')),
        ];
        [$from, $to, $label] = ea_resolve_period($today, $period);
        $tenantId = (int) (optional(app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null)->id ?? 0);
        return [$tenantId, $from, $to, $label, $period];
    }

    public function dossiers(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        $key  = 'logi_dossiers_v1_' . md5(json_encode($period));
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->dossiers($from, $to));
        return view('logistics.dossiers', [
            'data' => $data, 'active' => 'logistics_dossiers', 'period' => $period, 'periodLabel' => $label,
        ]);
    }

    public function workflow(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        $key  = 'logi_workflow_v1_' . md5(json_encode($period));
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->workflow($from, $to));
        return view('logistics.workflow', [
            'data' => $data, 'active' => 'logistics_workflow', 'period' => $period, 'periodLabel' => $label,
        ]);
    }

    public function volumes(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        $key  = 'logi_volumes_v1_' . md5(json_encode($period));
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->volumes($from, $to));
        return view('logistics.volumes', [
            'data' => $data, 'active' => 'logistics_volumes', 'period' => $period, 'periodLabel' => $label,
        ]);
    }
}
