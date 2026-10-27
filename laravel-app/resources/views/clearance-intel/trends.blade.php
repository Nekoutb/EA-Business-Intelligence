@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $T = $data['trends']; $K = $data['kpis'];
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td = 'px-2 py-1.5 text-[12px] text-[#2d3748]';
        $series = ['files_opened', 'files_billed', 'wip_files', 'fees', 'revenue_per_file', 'net_margin_pct', 'net_margin_per_file',
                   'files_per_employee_month', 'turnaround_days', 'ops_to_bill_days', 'steps_late_pct', 'expense_cycle_days',
                   'debours_engaged', 'undercharge', 'commission_waived', 'reopen_rate'];
    @endphp
    <p class="text-[12px] text-[#5b5547] mb-4">Twelve months ending {{ \App\Services\ClearanceIntel\Fmt::d($data['to']) }}. Each month is measured on its own; the dashed line is the target where one is set.</p>
    <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
        @foreach ($series as $key)
            @php
                $kk = $K[$key];
                $ds = [['label' => $kk['label'], 'data' => array_map(fn ($r) => $r[$key] ?? null, array_values($T)), 'color' => '#1e3d44']];
                if ($kk['target'] !== null) $ds[] = ['label' => 'Target', 'data' => array_fill(0, count($T), $kk['target']), 'color' => '#b07f3b', 'borderDash' => [5, 4], 'pointRadius' => 0];
            @endphp
            <div class="{{ $card }}">
                <div class="text-[12px] font-semibold text-[#1e3d44]" title="{{ $kk['definition'] }}">{{ $kk['label'] }}</div>
                @include('clearance-intel._chart', ['h' => 'h-36', 'cfg' => ['type' => 'line', 'fmt' => $kk['unit'] === 'xaf' ? 'xaf' : ($kk['unit'] === 'pct' ? 'pct' : ($kk['unit'] === 'days' ? 'days' : 'int')),
                    'labels' => array_column($T, 'label'), 'datasets' => $ds]])
            </div>
        @endforeach
    </div>

    <div class="{{ $card }}">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Indicator</th>
                    @foreach ($T as $r)<th class="{{ $th }} text-right whitespace-nowrap">{{ $r['label'] }}</th>@endforeach
                </tr></thead>
                <tbody>
                @foreach ($series as $key)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }} font-medium whitespace-nowrap">{{ $K[$key]['label'] }}</td>
                        @foreach ($T as $r)
                            @php $rag = \App\Services\ClearanceIntel\Targets::rag($key, ($r[$key] ?? null) === null ? null : (float) $r[$key]); @endphp
                            <td class="{{ $td }} text-right tabular-nums whitespace-nowrap {{ $rag === 'red' ? 'text-rose-700 font-semibold' : ($rag === 'green' ? 'text-emerald-700' : '') }}">{{ \App\Services\ClearanceIntel\Fmt::v($r[$key] ?? null, $K[$key]['unit']) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
