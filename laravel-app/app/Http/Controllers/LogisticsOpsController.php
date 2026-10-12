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
        // Period comes ONLY from the sidebar master filter (session-synced);
        // these pages deliberately carry no filter of their own.
        $today  = new \DateTimeImmutable();
        $period = current_period();
        [$from, $to, $label] = ea_resolve_period($today, $period);
        $tenantId = (int) (optional(app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null)->id ?? 0);
        return [LogisticsOpsService::dataTenant($tenantId), $from, $to, $label, $period];
    }

    public function dossiers(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        // Yearly windows list openings per month, monthly ones per day.
        $grain = in_array((string) ($period['type'] ?? 'ytd'), ['mtd', 'month'], true) ? 'day' : 'month';
        $key  = 'logi_dossiers_v4_' . md5(json_encode($period)) . '_' . $grain;
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->dossiers($from, $to, $grain));
        return view('logistics.dossiers', [
            'data' => $data, 'active' => 'logistics_dossiers', 'period' => $period, 'periodLabel' => $label,
        ]);
    }

    public function workflow(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        $key  = 'logi_workflow_v2_' . md5(json_encode($period));
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->workflow($from, $to));
        // Stage latency across the file lifecycle, narrowed by the page's own
        // customer / file filters on top of the master period.
        $customer = trim((string) $request->query('lg_customer', '')) ?: null;
        $file     = trim((string) $request->query('lg_file', '')) ?: null;
        $skey  = 'logi_stages_v1_' . md5(json_encode([$period, $customer, $file]));
        $stages = ea_cached($skey, fn () => (new LogisticsOpsService($tenantId))->stageLatency($from, $to, $customer, $file));
        return view('logistics.workflow', [
            'data' => $data, 'stages' => $stages, 'active' => 'logistics_workflow', 'period' => $period, 'periodLabel' => $label,
        ]);
    }

    public function reports(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        $svc  = new LogisticsOpsService($tenantId);
        $key  = 'logi_reports_v2_' . md5(json_encode($period));
        $data = ea_cached($key, function () use ($svc, $from, $to) {
            $summary  = $svc->revenueByCustomer($from, $to);
            $invoices = $svc->customerInvoices($from, $to);
            $byClient = [];
            foreach ($invoices as $inv) { $byClient[$inv['client']][] = $inv; }
            $summary['invoices_by_client'] = $byClient;
            return $summary;
        });
        return view('logistics.reports', [
            'data' => $data, 'active' => 'reports_logistics', 'period' => $period, 'periodLabel' => $label,
        ]);
    }

    public function oop(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        $key  = 'logi_oop_v2_' . md5(json_encode($period));
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->oopCompliance($from, $to));
        return view('logistics.oop', [
            'data' => $data, 'active' => 'logistics_oop', 'period' => $period, 'periodLabel' => $label,
        ]);
    }

    public function volumes(Request $request)
    {
        [$tenantId, $from, $to, $label, $period] = $this->ctx($request);
        // Yearly windows chart the evolution per month. Monthly windows let
        // the report user choose daily or weekly buckets (?g=day|week) —
        // the one page-level switch these pages carry, since it changes
        // the chart's resolution rather than the period.
        $monthly = in_array((string) ($period['type'] ?? 'ytd'), ['mtd', 'month'], true);
        $grain   = $monthly ? ($request->query('g') === 'week' ? 'week' : 'day') : 'month';
        $key  = 'logi_volumes_v2_' . md5(json_encode($period)) . '_' . $grain;
        $data = ea_cached($key, fn () => (new LogisticsOpsService($tenantId))->volumes($from, $to, $grain));
        return view('logistics.volumes', [
            'data' => $data, 'active' => 'logistics_volumes', 'period' => $period, 'periodLabel' => $label,
            'grain' => $grain, 'grainChoice' => $monthly,
        ]);
    }
}
