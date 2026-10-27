@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $K = $data['kpis']; $T = $data['trends']; $B = $data['breakdowns'];
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $h3 = 'text-[13px] font-semibold text-[#1e3d44]';
        $note = 'text-[11px] text-[#7c7466] mb-2';
        $x = fn ($k) => \App\Services\ClearanceIntel\Fmt::full($K[$k]['value'] === null ? null : (float) $K[$k]['value']);
        $pcols = ['fees' => ['Fees HT', 'xaf'], 'fee_per_file' => ['Fees / file', 'xaf'], 'undercharge' => ['Absorbed', 'xaf'],
                  'contribution' => ['Contribution', 'xaf'], 'net' => ['Net margin', 'xaf'], 'net_per_file' => ['Net / file', 'xaf'], 'margin_pct' => ['Margin %', 'pct']];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        @foreach (['fees', 'revenue_per_file', 'contribution', 'overhead_per_file', 'net_margin', 'net_margin_pct', 'net_margin_per_file', 'loss_files_pct',
                   'fee_had', 'fee_commission', 'fee_file', 'commission_yield_pct'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">From fees to net margin</h3>
            <p class="{{ $note }}">Global profitability of the clearance activity for the period, XAF.</p>
            <table class="w-full text-[12px]">
                <tr class="border-t border-[#f1ede5]"><td class="py-1.5">HAD (honoraires agréés en douane)</td><td class="text-right tabular-nums">{{ $x('fee_had') }}</td></tr>
                <tr class="border-t border-[#f1ede5]"><td class="py-1.5">Commission on débours</td><td class="text-right tabular-nums">{{ $x('fee_commission') }}</td></tr>
                <tr class="border-t border-[#f1ede5]"><td class="py-1.5">Frais de dossier</td><td class="text-right tabular-nums">{{ $x('fee_file') }}</td></tr>
                <tr class="border-t border-[#f1ede5]"><td class="py-1.5">Other services</td><td class="text-right tabular-nums">{{ $x('fee_other') }}</td></tr>
                <tr class="border-t-2 border-[#1e3d44] font-semibold"><td class="py-1.5">Fee revenue</td><td class="text-right tabular-nums">{{ $x('fees') }}</td></tr>
                <tr class="border-t border-[#f1ede5]"><td class="py-1.5">− Débours absorbed (recharged below cost)</td><td class="text-right tabular-nums text-rose-700">{{ $x('undercharge') }}</td></tr>
                <tr class="border-t-2 border-[#1e3d44] font-semibold"><td class="py-1.5">Contribution (incl. any margin on débours)</td><td class="text-right tabular-nums">{{ $x('contribution') }}</td></tr>
                <tr class="border-t border-[#f1ede5]"><td class="py-1.5">− Operating costs (class 6)</td><td class="text-right tabular-nums text-rose-700">{{ $x('overhead') }}</td></tr>
                <tr class="border-t-2 border-[#1e3d44] font-semibold"><td class="py-1.5">Net margin</td><td class="text-right tabular-nums">{{ $x('net_margin') }}</td></tr>
            </table>
            <p class="text-[11px] text-[#7c7466] mt-2">Débours themselves are pass-through: paid for the client and recharged at cost, they are neither revenue nor cost here.</p>
        </div>
        <div class="{{ $card }} lg:col-span-2">
            <h3 class="{{ $h3 }}">Revenue streams per month — 12 months</h3>
            <p class="{{ $note }}">Fees HT by nature, credit notes deducted.</p>
            @include('clearance-intel._chart', ['h' => 'h-64', 'cfg' => ['type' => 'bar', 'fmt' => 'xaf', 'stacked' => true, 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'HAD', 'data' => array_column($T, 'fee_had'), 'color' => '#1e3d44'],
                ['label' => 'Commission', 'data' => array_column($T, 'fee_commission'), 'color' => '#b07f3b'],
                ['label' => 'Frais de dossier', 'data' => array_column($T, 'fee_file'), 'color' => '#5b8c85'],
                ['label' => 'Other', 'data' => array_column($T, 'fee_other'), 'color' => '#d9a866'],
            ]]])
        </div>
    </div>

    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Margin per file — 12 months</h3>
        <p class="{{ $note }}">Fees per file against operating cost per file. Net margin per file is the difference, after débours absorbed.</p>
        @include('clearance-intel._chart', ['cfg' => ['type' => 'line', 'fmt' => 'xaf', 'labels' => array_column($T, 'label'), 'datasets' => [
            ['label' => 'Fees per file', 'data' => array_column($T, 'revenue_per_file'), 'color' => '#1e3d44'],
            ['label' => 'Operating cost per file', 'data' => array_column($T, 'overhead_per_file'), 'color' => '#c0392b'],
            ['label' => 'Net margin per file', 'data' => array_column($T, 'net_margin_per_file'), 'color' => '#b07f3b'],
        ]]])
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }} lg:col-span-2">
            <h3 class="{{ $h3 }}">Profitability by service (files billed in the period)</h3>
            @include('clearance-intel._group_table', ['rows' => $B['profit_service'], 'first' => 'Service', 'cols' => $pcols])
        </div>
        <div class="{{ $card }} lg:col-span-2">
            <h3 class="{{ $h3 }}">Profitability by client (15 largest by fees)</h3>
            @include('clearance-intel._group_table', ['rows' => $B['profit_client'], 'first' => 'Client', 'cols' => $pcols])
        </div>
        <div class="{{ $card }} lg:col-span-2">
            <h3 class="{{ $h3 }}">Profitability by customs regime</h3>
            @include('clearance-intel._group_table', ['rows' => $B['profit_regime'], 'first' => 'Regime', 'cols' => $pcols])
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Least profitable files billed</h3>
            @include('clearance-intel._files_table', ['rows' => $B['worst_files'], 'cols' => ['fees' => ['Fees', 'xaf'], 'net' => ['Net', 'xaf']]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Most profitable files billed</h3>
            @include('clearance-intel._files_table', ['rows' => $B['best_files'], 'cols' => ['fees' => ['Fees', 'xaf'], 'net' => ['Net', 'xaf']]])
        </div>
    </div>

    <h2 class="text-[13px] font-semibold text-[#1e3d44] mb-2 mt-6">Collections</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach (['ar_open', 'ar_overdue_pct', 'dso', 'debours_engaged'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>
    <p class="text-[11px] text-[#7c7466] mt-2">Operating cost per file = class 6 costs of the month ÷ files billed that month. A month with costs and no file billed leaves its files' net margin blank.</p>
@endif
@endsection
