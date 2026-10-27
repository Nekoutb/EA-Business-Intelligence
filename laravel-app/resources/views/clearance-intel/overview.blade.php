@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $K = $data['kpis'];
        $T = $data['trends'];
        $trig = (array) config('clearance_intel.triggers');
        $ciQs = $filter ? '&' . http_build_query($filter) : '';
        $flagged = array_filter($data['files'], fn ($f) => count($f['flags']) > 0 && in_array($f['state'], ['draft', 'in_progress', 'ops_closed', 'done'], true));
        usort($flagged, fn ($a, $b) => count($b['flags']) <=> count($a['flags']));
    @endphp

    {{-- Alerts: every KPI past its trigger --}}
    <div class="rounded-xl border {{ $data['alerts'] ? 'border-rose-200 bg-rose-50/60' : 'border-emerald-200 bg-emerald-50/60' }} p-4 mb-5">
        <div class="text-sm font-semibold {{ $data['alerts'] ? 'text-rose-900' : 'text-emerald-900' }}">
            {{ $data['alerts'] ? count($data['alerts']) . ' indicator' . (count($data['alerts']) > 1 ? 's' : '') . ' past the alert trigger' : 'No indicator past its alert trigger' }}
        </div>
        @if ($data['alerts'])
            <div class="grid md:grid-cols-2 gap-x-6 gap-y-1 mt-2">
                @foreach ($data['alerts'] as $a)
                    @php $x = $K[$a]; @endphp
                    <div class="text-[12px] text-rose-900">
                        <b>{{ $x['label'] }}</b>: {{ \App\Services\ClearanceIntel\Fmt::v($x['value'], $x['unit']) }}
                        <span class="text-rose-700">(alert {{ $x['better'] === 'down' ? 'above' : 'below' }} {{ \App\Services\ClearanceIntel\Fmt::v($x['trigger'], $x['unit']) }}, target {{ \App\Services\ClearanceIntel\Fmt::v($x['target'], $x['unit']) }})</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <h2 class="text-[13px] font-semibold text-[#1e3d44] mb-2">Headline indicators</h2>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 mb-5">
        @foreach (['files_billed', 'fees', 'revenue_per_file', 'net_margin_pct', 'net_margin_per_file',
                   'files_per_employee_month', 'turnaround_days', 'ops_to_bill_days', 'steps_late_pct', 'wip_files',
                   'unbilled_debours', 'unjustified_advances', 'commission_waived', 'dso', 'fy_fees'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-5">
        <div class="bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm">
            <h3 class="text-[13px] font-semibold text-[#1e3d44]">Fee revenue per month — 12 months</h3>
            <p class="text-[11px] text-[#7c7466] mb-2">Fees HT invoiced on clearance files, credit notes deducted; débours excluded.</p>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'bar', 'fmt' => 'xaf', 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'Fees HT', 'data' => array_column($T, 'fees'), 'color' => '#1e3d44'],
            ]]])
        </div>
        <div class="bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm">
            <h3 class="text-[13px] font-semibold text-[#1e3d44]">Fee revenue vs net margin per file — 12 months</h3>
            <p class="text-[11px] text-[#7c7466] mb-2">The gap between the lines is the operating cost carried by each file.</p>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'line', 'fmt' => 'xaf', 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'Fees per file', 'data' => array_column($T, 'revenue_per_file'), 'color' => '#1e3d44'],
                ['label' => 'Net margin per file', 'data' => array_column($T, 'net_margin_per_file'), 'color' => '#b07f3b'],
            ]]])
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm">
            <h3 class="text-[13px] font-semibold text-[#1e3d44] mb-2">File triggers raised now</h3>
            <table class="w-full">
                @foreach ($trig as $tk => [$tl, $td])
                    <tr class="border-t border-[#f1ede5]">
                        <td class="py-1.5 text-[12px]"><a class="hover:underline text-brand-700" href="{{ tenant_url('/clearance/files') }}?flag={{ $tk }}{{ $ciQs }}">{{ $tl }}</a></td>
                        <td class="py-1.5 text-[12px] text-right tabular-nums font-semibold {{ ($data['flag_counts'][$tk] ?? 0) ? 'text-rose-700' : 'text-[#8a8578]' }}">{{ $data['flag_counts'][$tk] ?? 0 }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div class="lg:col-span-2 bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm">
            <h3 class="text-[13px] font-semibold text-[#1e3d44] mb-2">Files needing attention</h3>
            @include('clearance-intel._files_table', ['rows' => array_slice($flagged, 0, 10), 'cols' => ['fees' => ['Fees', 'xaf'], 'unbilled_debours' => ['Débours to recharge', 'xaf']]])
        </div>
    </div>
@endif
@endsection
