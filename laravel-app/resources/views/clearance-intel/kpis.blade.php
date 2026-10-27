@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@php
    $sections = (array) config('clearance_intel.sections');
    $kpis = $data['kpis'] ?? [];
    $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
    $td = 'px-2 py-2 text-[12px] text-[#2d3748] align-top';
    $inp = 'w-24 text-[12px] px-2 py-1 border border-[#e8e4de] rounded-md text-right ' . ($canEdit ? 'bg-white' : 'bg-[#f5f2ec] text-[#8a8578] cursor-not-allowed');
    $units = ['int' => 'count', 'xaf' => 'XAF', 'pct' => '%', 'days' => 'days', 'ratio' => 'ratio'];
@endphp
<div class="bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm mb-4 text-[12px] text-[#2d3748]">
    <b>How to read it.</b> Every indicator has a definition and a formula. Where management sets a <b>target</b>, the indicator shows green when it meets it;
    past the <b>alert trigger</b> it turns red and appears in the Executive overview's alerts. Between the two it is amber ("watch").
    Indicators marked ▲ are better higher, ▼ better lower. Figures shown are for {{ $periodLabel }}. The values loaded with the library are proposals: set your own.
    @unless ($canEdit)<div class="mt-1 text-[#8a6a2f]">Only an administrator can change targets.</div>@endunless
</div>

<form method="POST" action="{{ tenant_url('/clearance/kpis') }}">
    @csrf
    @foreach ($sections as $sk => $sl)
        <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm mb-4 overflow-x-auto">
            <h2 class="text-[13px] font-semibold text-[#1e3d44] px-4 pt-3 pb-2">{{ $sl }}</h2>
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left w-56">Indicator</th><th class="{{ $th }} text-left">Definition and formula</th>
                    <th class="{{ $th }} text-right">Now</th><th class="{{ $th }} text-right">Target</th><th class="{{ $th }} text-right">Alert trigger</th>
                </tr></thead>
                <tbody>
                @foreach ((array) config('clearance_intel.kpis') as $key => $def)
                    @continue($def[0] !== $sk)
                    @php
                        $t = \App\Services\ClearanceIntel\Targets::forKpi($key);
                        $now = $kpis[$key] ?? null;
                        $rs = \App\Services\ClearanceIntel\Fmt::ragStyle($now['rag'] ?? null);
                    @endphp
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }} font-semibold">{{ $def[1] }} <span class="text-[#8a8578] font-normal">{{ $def[3] === 'up' ? '▲' : ($def[3] === 'down' ? '▼' : '') }} {{ $units[$def[2]] ?? '' }}</span></td>
                        <td class="{{ $td }}">{{ $def[4] }}<div class="text-[11px] text-[#8a8578] font-mono mt-0.5">{{ $def[5] }}</div></td>
                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">
                            {{ $now ? \App\Services\ClearanceIntel\Fmt::v($now['value'], $def[2]) : '—' }}
                            @if ($rs['word'])<div><span class="text-[10px] px-1.5 py-0.5 rounded {{ $rs['chip'] }}">{{ $rs['word'] }}</span></div>@endif
                        </td>
                        @if ($def[3] === null)
                            <td class="{{ $td }} text-right text-[#8a8578]" colspan="2">information only</td>
                        @else
                            <td class="{{ $td }} text-right"><input name="kpi[{{ $key }}][target]" value="{{ $t['target'] }}" @disabled(!$canEdit) class="{{ $inp }}" inputmode="decimal" aria-label="Target for {{ $def[1] }}"></td>
                            <td class="{{ $td }} text-right"><input name="kpi[{{ $key }}][trigger]" value="{{ $t['trigger'] }}" @disabled(!$canEdit) class="{{ $inp }}" inputmode="decimal" aria-label="Alert trigger for {{ $def[1] }}"></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm mb-4 overflow-x-auto">
        <h2 class="text-[13px] font-semibold text-[#1e3d44] px-4 pt-3 pb-2">File triggers</h2>
        <p class="text-[11px] text-[#7c7466] px-4 pb-2">Rules checked on every file; a file meeting one carries the flag on the drilldown, the leakage page and the overview. Where a rule has a number of days, you can change it.</p>
        <table class="w-full">
            <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left w-56">Trigger</th><th class="{{ $th }} text-left">Rule</th><th class="{{ $th }} text-right">Days</th><th class="{{ $th }} text-right">Files now</th></tr></thead>
            <tbody>
            @foreach ((array) config('clearance_intel.triggers') as $tk => [$tl, $tr, $tdays])
                <tr class="border-t border-[#f1ede5]">
                    <td class="{{ $td }} font-semibold">{{ $tl }}</td>
                    <td class="{{ $td }}">{{ str_replace('{days}', 'N', $tr) }}</td>
                    <td class="{{ $td }} text-right">
                        @if (str_contains($tr, '{days}'))
                            <input name="trigger[{{ $tk }}]" value="{{ \App\Services\ClearanceIntel\Targets::triggerDays($tk) }}" @disabled(!$canEdit) class="{{ $inp }}" inputmode="numeric" aria-label="Days for {{ $tl }}">
                        @else — @endif
                    </td>
                    <td class="{{ $td }} text-right tabular-nums">{{ $data['flag_counts'][$tk] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($canEdit)
        <div class="flex justify-end"><button class="text-[13px] font-semibold px-4 py-2 rounded-md bg-brand-600 text-white">Save targets</button></div>
    @endif
</form>
@endsection
