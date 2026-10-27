@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $h3 = 'text-[13px] font-semibold text-[#1e3d44]';
        $note = 'text-[11px] text-[#7c7466] mb-2';
        $trig = (array) config('clearance_intel.triggers');
        $byFlag = [];
        foreach ($data['files'] as $f) foreach ($f['flags'] as $fl) $byFlag[$fl][] = $f;
        $sections = [
            'debours_left'   => ['unbilled_debours' => ['Débours to recharge', 'xaf'], 'debours_incurred' => ['Incurred', 'xaf'], 'debours_billed' => ['Billed', 'xaf']],
            'unbilled'       => ['debours_incurred' => ['Débours engaged', 'xaf']],
            'absorbed'       => ['undercharge' => ['Absorbed', 'xaf'], 'fees' => ['Fees', 'xaf']],
            'commission_off' => ['commission_waived' => ['Commission lost', 'xaf'], 'debours_billed' => ['Débours billed', 'xaf']],
            'advance_open'   => ['unjustified' => ['Unjustified', 'xaf']],
            'loss'           => ['fees' => ['Fees', 'xaf'], 'overhead' => ['Cost share', 'xaf'], 'net' => ['Net', 'xaf']],
            'overdue'        => ['balance_due' => ['Balance due', 'xaf']],
            'reopened'       => ['reopens' => ['Re-openings', 'int'], 'fees' => ['Fees', 'xaf']],
            'waived'         => ['fees' => ['Fees', 'xaf']],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        @foreach (['unbilled_debours', 'ops_closed_unbilled', 'undercharge', 'commission_waived', 'unjustified_advances', 'advances_aged',
                   'waivers', 'reopen_rate', 'credit_notes', 'voided_invoices', 'cancelled_debours', 'cash_need'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>

    @foreach ($sections as $fl => $cols)
        @php $rows = $byFlag[$fl] ?? []; usort($rows, fn ($a, $b) => array_sum(array_map(fn ($c) => abs((float) ($b[$c] ?? 0)), array_keys($cols))) <=> array_sum(array_map(fn ($c) => abs((float) ($a[$c] ?? 0)), array_keys($cols)))); @endphp
        <div class="{{ $card }} mb-4">
            <div class="flex items-baseline justify-between gap-2 flex-wrap">
                <h3 class="{{ $h3 }}">{{ $trig[$fl][0] }} <span class="{{ $rows ? 'text-rose-700' : 'text-[#8a8578]' }}">· {{ count($rows) }} file{{ count($rows) === 1 ? '' : 's' }}</span></h3>
                @if (count($rows) > 15)<a class="text-[12px] text-brand-700 hover:underline" href="{{ tenant_url('/clearance/files') }}?flag={{ $fl }}">all {{ count($rows) }} →</a>@endif
            </div>
            <p class="{{ $note }}">{{ str_replace('{days}', \App\Services\ClearanceIntel\Targets::triggerDays($fl), $trig[$fl][1]) }}</p>
            @if ($rows)
                @include('clearance-intel._files_table', ['rows' => array_slice($rows, 0, 15), 'cols' => $cols])
            @endif
        </div>
    @endforeach
@endif
@endsection
