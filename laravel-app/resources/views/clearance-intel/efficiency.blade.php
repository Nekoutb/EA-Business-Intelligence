@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $T = $data['trends']; $B = $data['breakdowns'];
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $h3 = 'text-[13px] font-semibold text-[#1e3d44]';
        $note = 'text-[11px] text-[#7c7466] mb-2';
        $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td = 'px-2 py-1.5 text-[12px] text-[#2d3748]';
        $d1 = fn ($v) => $v === null ? '—' : number_format((float) $v, 1);
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        @foreach (['turnaround_days', 'transit_days', 'open_to_start_days', 'start_to_ops_close_days', 'ops_to_bill_days', 'open_to_bill_days',
                   'steps_late_pct', 'expense_cycle_days', 'justification_days', 'tasks_open', 'tasks_oldest_days', 'docs_complete_pct'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Where the days go — 12 months</h3>
            <p class="{{ $note }}">Average days per stage of a file, for files that completed each stage in the month.</p>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'bar', 'fmt' => 'days', 'stacked' => true, 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'Opening → started', 'data' => array_column($T, 'open_to_start_days'), 'color' => '#d9a866'],
                ['label' => 'Work → closed for operations', 'data' => array_column($T, 'start_to_ops_close_days'), 'color' => '#5b8c85'],
                ['label' => 'Closed for operations → invoiced', 'data' => array_column($T, 'ops_to_bill_days'), 'color' => '#c0392b'],
            ]]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Steps over target and overall turnaround — 12 months</h3>
            <p class="{{ $note }}">Share of workflow steps late against the targets configured in the clearance application.</p>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'line', 'fmt' => 'pct', 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'Steps over target (%)', 'data' => array_column($T, 'steps_late_pct'), 'color' => '#c0392b'],
                ['label' => 'Overall turnaround (days)', 'data' => array_column($T, 'turnaround_days'), 'color' => '#1e3d44'],
            ]]])
        </div>
    </div>

    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Turnaround by step against target</h3>
        <p class="{{ $note }}">Steps completed in the period (days elapsed), and steps still open now. Targets come from Clearance → Configuration → Turnaround targets.</p>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Step</th><th class="{{ $th }} text-right">Target (d)</th>
                    <th class="{{ $th }} text-right">Completed</th><th class="{{ $th }} text-right">Average (d)</th><th class="{{ $th }} text-right">Median</th>
                    <th class="{{ $th }} text-right">90th pct</th><th class="{{ $th }} text-right">Late %</th>
                    <th class="{{ $th }} text-right">Open now</th><th class="{{ $th }} text-right">Open and late</th>
                </tr></thead>
                <tbody>
                @forelse ($B['steps'] as $s)
                    @php $lateCls = $s['late_pct'] === null ? '' : ($s['late_pct'] > 25 ? 'text-rose-700 font-semibold' : ($s['late_pct'] > 10 ? 'text-amber-700' : 'text-emerald-700')); @endphp
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }}">{{ $s['label'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d1($s['target_days']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $s['done'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d1($s['avg']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d1($s['median']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d1($s['p90']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $lateCls }}">{{ $s['late_pct'] === null ? '—' : $s['late_pct'] . ' %' }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $s['open'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $s['open_late'] ? 'text-rose-700 font-semibold' : '' }}">{{ $s['open_late'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="{{ $td }} text-[#8a8578] py-4">No measured steps yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="{{ $card }}">
        <h3 class="{{ $h3 }}">Queues now — what is waiting, and for how long</h3>
        <p class="{{ $note }}">Every action waiting on somebody in the clearance application (My Tasks), by kind and age since it landed.</p>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Action</th><th class="{{ $th }} text-right">Waiting</th><th class="{{ $th }} text-right">Amount</th>
                    <th class="{{ $th }} text-right">≤ 1 d</th><th class="{{ $th }} text-right">2–3 d</th><th class="{{ $th }} text-right">4–7 d</th><th class="{{ $th }} text-right">&gt; 7 d</th>
                    <th class="{{ $th }} text-right">Oldest (d)</th>
                </tr></thead>
                <tbody>
                @forelse ($B['queues'] as $q)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }}">{{ $q['kind'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums font-semibold">{{ $q['count'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $q['amount'] ? \App\Services\ClearanceIntel\Fmt::xaf($q['amount']) : '—' }}</td>
                        @foreach ($q['b'] as $i => $n)<td class="{{ $td }} text-right tabular-nums {{ $i === 3 && $n ? 'text-rose-700 font-semibold' : '' }}">{{ $n }}</td>@endforeach
                        <td class="{{ $td }} text-right tabular-nums">{{ $q['oldest'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="{{ $td }} text-[#8a8578] py-4">Nothing is waiting.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
