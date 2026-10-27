@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $F = $data['forecast']; $M = $F['months'];
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $h3 = 'text-[13px] font-semibold text-[#1e3d44]';
        $note = 'text-[11px] text-[#7c7466] mb-2';
        $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td = 'px-2 py-1.5 text-[12px] text-[#2d3748]';
        $basis = $F['basis_months'] ? implode(', ', array_map(fn ($ym) => (new \DateTimeImmutable($ym . '-01'))->format('M Y'), $F['basis_months'])) : 'no full month yet';
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-2">
        @foreach (['fy_files', 'fy_fees', 'pipeline_fees', 'pipeline_debours', 'cash_need'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>
    <p class="text-[11px] text-[#7c7466] mb-5">Method: actual full months, then every remaining month (the current one included) at the average of the last three full months ({{ $basis }}): {{ number_format($F['avg_files'], 1) }} files and {{ \App\Services\ClearanceIntel\Fmt::xaf($F['avg_fees']) }} of fees a month. A run-rate, not a budget: it assumes the recent pace holds.</p>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Fee revenue — actual and projected, {{ substr($data['to'], 0, 4) }}</h3>
            @include('clearance-intel._chart', ['h' => 'h-64', 'cfg' => ['type' => 'bar', 'fmt' => 'xaf', 'stacked' => true, 'labels' => array_column($M, 'label'), 'datasets' => [
                ['label' => 'Actual', 'data' => array_map(fn ($r) => $r['forecast_fees'] === null ? $r['actual_fees'] : null, array_values($M)), 'color' => '#1e3d44'],
                ['label' => 'Projected', 'data' => array_column($M, 'forecast_fees'), 'color' => '#d9a866'],
            ]]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Files billed — actual and projected, {{ substr($data['to'], 0, 4) }}</h3>
            @include('clearance-intel._chart', ['h' => 'h-64', 'cfg' => ['type' => 'bar', 'fmt' => 'int', 'stacked' => true, 'labels' => array_column($M, 'label'), 'datasets' => [
                ['label' => 'Actual', 'data' => array_map(fn ($r) => $r['forecast_files'] === null ? $r['actual_files'] : null, array_values($M)), 'color' => '#1e3d44'],
                ['label' => 'Projected', 'data' => array_column($M, 'forecast_files'), 'color' => '#d9a866'],
            ]]])
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Pipeline: work in progress turning into invoices</h3>
            <p class="{{ $note }}">{{ $F['pipeline_files'] }} live files. Expected fees at {{ \App\Services\ClearanceIntel\Fmt::xaf($F['avg_fee_90']) }} per file (average of files billed in the last 90 days); débours already engaged come back when each file is billed.</p>
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left">State</th><th class="{{ $th }} text-right">Files</th>
                    <th class="{{ $th }} text-right">Expected fees</th><th class="{{ $th }} text-right">Débours to recharge</th></tr></thead>
                <tbody>
                @forelse ($F['pipeline_by_state'] as $st => $r)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }}">{{ $st }}</td><td class="{{ $td }} text-right tabular-nums">{{ $r['files'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $F['avg_fee_90'] === null ? '—' : \App\Services\ClearanceIntel\Fmt::xaf($r['files'] * $F['avg_fee_90']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::xaf($r['debours']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="{{ $td }} text-[#8a8578] py-4">No live files.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Month by month</h3>
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left">Month</th><th class="{{ $th }} text-right">Files</th><th class="{{ $th }} text-right">Fees</th><th class="{{ $th }} text-left">Basis</th></tr></thead>
                <tbody>
                @foreach ($M as $r)
                    @php $proj = $r['forecast_fees'] !== null; @endphp
                    <tr class="border-t border-[#f1ede5] {{ $proj ? 'text-[#8a6a2f]' : '' }}">
                        <td class="{{ $td }}">{{ $r['label'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $proj ? number_format($r['forecast_files'], 1) : number_format((int) $r['actual_files']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::xaf($proj ? $r['forecast_fees'] : $r['actual_fees']) }}</td>
                        <td class="{{ $td }} text-[11px]">{{ $proj ? ($r['partial'] ? 'projected (month in progress: ' . number_format((int) $r['actual_files']) . ' billed so far)' : 'projected') : 'actual' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
