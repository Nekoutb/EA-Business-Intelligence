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
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        @foreach (['files_opened', 'files_started', 'files_ops_closed', 'files_billed', 'files_closed', 'wip_files', 'eq_containers', 'backlog_months'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>

    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Flow of files — 12 months</h3>
        <p class="{{ $note }}">Files entering (opened) against files leaving the pipeline (closed for operations, billed). When openings run ahead of billing, the backlog grows.</p>
        @include('clearance-intel._chart', ['h' => 'h-64', 'cfg' => ['type' => 'bar', 'fmt' => 'int', 'labels' => array_column($T, 'label'), 'datasets' => [
            ['label' => 'Opened', 'data' => array_column($T, 'files_opened'), 'color' => '#d9a866'],
            ['label' => 'Closed for operations', 'data' => array_column($T, 'files_ops_closed'), 'color' => '#5b8c85'],
            ['label' => 'Billed', 'data' => array_column($T, 'files_billed'), 'color' => '#1e3d44'],
        ]]])
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Files opened by service</h3>
            <p class="{{ $note }}">Activity mix: what the period's work consisted of.</p>
            @include('clearance-intel._group_table', ['rows' => $B['by_service'], 'first' => 'Service', 'cols' => ['eq_containers' => ['Eq. containers', 'int'], 'weight' => ['Weight (kg)', 'int']]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Files opened by customs regime</h3>
            <p class="{{ $note }}">IM4 release for consumption, IM5 temporary admission, IM7 warehouse, IM8 transit, IM9 other.</p>
            @include('clearance-intel._group_table', ['rows' => $B['by_regime'], 'first' => 'Regime', 'cols' => ['eq_containers' => ['Eq. containers', 'int']]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Top clients by files opened</h3>
            <p class="{{ $note }}">Client concentration of the workload (15 largest).</p>
            @include('clearance-intel._group_table', ['rows' => $B['by_client'], 'first' => 'Client', 'cols' => ['eq_containers' => ['Eq. containers', 'int']]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Files opened by port and shipment type</h3>
            @include('clearance-intel._group_table', ['rows' => $B['by_port'], 'first' => 'Port'])
            <div class="mt-3"></div>
            @include('clearance-intel._group_table', ['rows' => $B['by_shipment'], 'first' => 'Shipment type'])
        </div>
    </div>

    <h2 class="text-[13px] font-semibold text-[#1e3d44] mb-2 mt-6">Work in progress now</h2>
    <div class="grid lg:grid-cols-3 gap-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">By state</h3>
            @include('clearance-intel._group_table', ['rows' => $B['wip_state'], 'first' => 'State', 'cols' => ['debours_incurred' => ['Débours engaged', 'xaf']]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">By whose desk it sits on</h3>
            <p class="{{ $note }}">The clearance application's own "sitting with" for each live file.</p>
            @include('clearance-intel._group_table', ['rows' => $B['wip_sitting'], 'first' => 'Sitting with', 'cols' => ['debours_incurred' => ['Débours engaged', 'xaf']]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">By age since opening</h3>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'bar', 'fmt' => 'int', 'labels' => array_keys($B['wip_age']), 'datasets' => [
                ['label' => 'Live files', 'data' => array_values($B['wip_age']), 'color' => '#1e3d44'],
            ]]])
        </div>
    </div>
@endif
@endsection
