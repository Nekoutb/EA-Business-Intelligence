@extends('layouts.app')
@section('title', 'Fixed Assets')

@section('page-title', 'Fixed Assets')
@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data)
        @php $ccy = $data['currency'] ?? 'USD'; @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
            <x-kpi title="Gross Book Value" :value="ea_money_short((float)($data['gross_value'] ?? 0), $ccy)" color="indigo"/>
            <x-kpi title="Net Book Value"   :value="ea_money_short((float)($data['net_value'] ?? 0), $ccy)" color="emerald"/>
            <x-kpi title="Depreciation YTD" :value="ea_money_short((float)($data['depreciation_ytd'] ?? 0), $ccy)" color="amber"/>
            <x-kpi title="Asset Count"      :value="number_format((int)($data['count'] ?? 0))" color="slate"/>
        </div>
    @endif
@endsection
