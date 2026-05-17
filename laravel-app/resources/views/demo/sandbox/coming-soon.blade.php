@extends('demo.sandbox.layout')
@section('title', $moduleLabel)

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · {{ $moduleService }}</div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $moduleLabel }}</h1>
        <p class="text-[12px] text-slate-500 mt-1">{{ $moduleSubtitle ?? 'Coming soon to the demo sandbox.' }}</p>
    </div>
@endsection

@section('content')
<div class="panel p-8 text-center max-w-2xl mx-auto">
    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-amber-100 mb-4">
        <svg class="w-7 h-7 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <h2 class="text-lg font-bold text-slate-900 mb-2">{{ $moduleLabel }} · coming soon</h2>
    <p class="text-[13px] text-slate-600 leading-relaxed mb-5 max-w-md mx-auto">
        {{ $moduleDescription ?? 'This module is live in the production BMS but not yet exposed in the demo sandbox.' }}
    </p>

    @if (!empty($moduleCapabilities))
        <div class="text-left bg-stone-50 border border-stone-200 rounded-lg p-4 mb-5">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">What this module does in production</div>
            <ul class="text-[12px] text-slate-700 space-y-1.5">
                @foreach ($moduleCapabilities as $cap)
                    <li class="flex items-start gap-2">
                        <span class="text-amber-600 mt-0.5">•</span>
                        <span>{{ $cap }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex items-center justify-center gap-2">
        <a href="/demo/sandbox" class="text-[12px] font-medium text-slate-600 border border-stone-300 hover:border-amber-500 hover:text-amber-700 rounded-lg px-3 py-2">← Back to dashboard</a>
        <a href="https://bms.cm-ea.com" target="_blank" rel="noopener" class="text-[12px] font-semibold text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 rounded-lg px-4 py-2 shadow">See the real BMS →</a>
    </div>
</div>
@endsection
