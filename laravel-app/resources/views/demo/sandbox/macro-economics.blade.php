@extends('demo.sandbox.layout')
@section('title', 'Macro Economics')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Macro Economics</div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $data['country'] }} macro indicators</h1>
        <p class="text-[12px] text-slate-500 mt-1">Context for planning / forecast assumptions · as of {{ $data['as_of'] }} · sources noted per indicator</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
    @foreach ($data['indicators'] as $i)
        @php
            $trendIcon = match($i['trend']) {
                'up'   => '↑',
                'down' => '↓',
                'flat' => '→',
                default => '·',
            };
            $trendCls = match($i['trend']) {
                'up'   => 'text-emerald-700',
                'down' => 'text-rose-700',
                'flat' => 'text-slate-500',
                default => 'text-slate-400',
            };
        @endphp
        <div class="panel p-4">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $i['label'] }}</div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-xl font-bold mono text-slate-900">{{ $i['value'] }}</span>
                <span class="text-base {{ $trendCls }}">{{ $trendIcon }}</span>
            </div>
            <div class="text-[10px] text-slate-500 mt-1">{{ $i['source'] }}</div>
        </div>
    @endforeach
</div>

<div class="mt-4 panel p-4 bg-stone-50/40 border border-stone-200">
    <div class="text-[10px] uppercase tracking-widest text-slate-700 font-semibold mb-1">How this drives the BMS</div>
    <p class="text-[11px] text-slate-600 leading-relaxed">
        Macro indicators feed downstream into the Forecast (CPI flows into the cost-inflation assumption), Investment Analysis (BEAC policy rate as the demo's discount-rate floor), and Working Capital (USD/XAF for any FX-denominated AR/AP).
        The real BMS pulls these via BEAC / INS / Bloomberg feeds; the demo uses a snapshot.
    </p>
</div>
@endsection
