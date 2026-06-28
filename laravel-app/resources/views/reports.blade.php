@extends('layouts.app')
@section('title', 'Reports')
@section('page-title', 'Reports')

@section('content')
@php
    $ccy = $currency ?? 'XAF';
    // Calendar geometry for the selected month.
    $firstOfMonth = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month)) ?: new \DateTimeImmutable('first day of this month');
    $daysInMonth  = (int) $firstOfMonth->format('t');
    $leadBlanks   = ((int) $firstOfMonth->format('N')) - 1;   // Mon=1 → 0 blanks
    $prevMonth    = $firstOfMonth->modify('-1 month');
    $nextMonth    = $firstOfMonth->modify('+1 month');
    $todayStr     = now()->format('Y-m-d');
    $dayUrl = fn ($d, $m, $y) => tenant_url('/reports?date=' . $d . '&month=' . $m . '&year=' . $y);
@endphp

@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm mb-6">{{ $error }}</div>
@endif

{{-- ── Calendar ──────────────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-slate-900">Select a day</h2>
        <form method="GET" action="{{ tenant_url('/reports') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" max="{{ $todayStr }}"
                   class="text-[13px] border border-slate-200 rounded-lg px-2 py-1 text-slate-700"/>
            <button type="submit" class="text-[12px] font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg px-3 py-1.5">Go</button>
        </form>
    </div>

    <div class="flex items-center justify-between mb-2">
        <a href="{{ $dayUrl($date, $prevMonth->format('n'), $prevMonth->format('Y')) }}"
           class="text-slate-500 hover:text-indigo-600 text-sm px-2 py-1 rounded-lg hover:bg-slate-50">&larr; {{ $prevMonth->format('M Y') }}</a>
        <div class="text-sm font-bold text-slate-900">{{ $firstOfMonth->format('F Y') }}</div>
        <a href="{{ $dayUrl($date, $nextMonth->format('n'), $nextMonth->format('Y')) }}"
           class="text-slate-500 hover:text-indigo-600 text-sm px-2 py-1 rounded-lg hover:bg-slate-50">{{ $nextMonth->format('M Y') }} &rarr;</a>
    </div>

    <div class="grid grid-cols-7 gap-1 text-center">
        @foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow)
            <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide py-1">{{ $dow }}</div>
        @endforeach
        @for ($b = 0; $b < $leadBlanks; $b++)
            <div></div>
        @endfor
        @for ($day = 1; $day <= $daysInMonth; $day++)
            @php
                $ds       = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $act      = $activity[$ds] ?? null;
                $selected = ($ds === $date);
                $isFuture = ($ds > $todayStr);
                $isToday  = ($ds === $todayStr);
            @endphp
            @if ($isFuture)
                <div class="rounded-lg py-2 text-[13px] text-slate-300">{{ $day }}</div>
            @else
                <a href="{{ $dayUrl($ds, $month, $year) }}"
                   class="rounded-lg py-1.5 text-[13px] flex flex-col items-center justify-center min-h-[44px] border transition
                          {{ $selected ? 'bg-indigo-600 text-white border-indigo-600 font-bold'
                              : ($act ? 'bg-indigo-50 text-indigo-700 border-indigo-100 hover:bg-indigo-100 font-semibold'
                                      : 'text-slate-600 border-transparent hover:bg-slate-50') }}
                          {{ $isToday && !$selected ? 'ring-1 ring-indigo-300' : '' }}">
                    <span>{{ $day }}</span>
                    @if ($act)
                        <span class="text-[9px] mt-0.5 {{ $selected ? 'text-indigo-100' : 'text-indigo-500' }}">{{ ea_money_short((float) $act['amount'], $ccy) }}</span>
                    @endif
                </a>
            @endif
        @endfor
    </div>
    <p class="text-[11px] text-slate-400 mt-3">Highlighted days have sales. Click a day to load its report below.</p>
</div>

@php $human = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('l, j F Y') ?? $date; @endphp

{{-- ── Sandbox 1 · Sales for the day ─────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm mb-6">
    <h2 class="text-sm font-semibold text-slate-900 mb-1">Sales · {{ $human }}</h2>
    <p class="text-[11px] text-slate-500 mb-4">Posted customer invoices issued on this day. Cash = settled (paid); Credit = on account (open).</p>

    @if (!$sales || ($sales['invoice_count'] ?? 0) === 0)
        <div class="text-[13px] text-slate-400 italic py-6 text-center">No sales recorded on this day.</div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
            <x-kpi title="Total sold" :value="ea_money_short((float)($sales['total'] ?? 0), $ccy)"
                   :sub="number_format((int)($sales['invoice_count'] ?? 0)) . ' invoices'" color="indigo"/>
            <x-kpi title="Outfits sold" :value="number_format((float)($sales['outfits'] ?? 0), 0)" color="slate"/>
            <x-kpi title="Cash sales" :value="ea_money_short((float)($sales['cash_total'] ?? 0), $ccy)"
                   :sub="number_format((int)($sales['cash_count'] ?? 0)) . ' settled'" color="sage"/>
            <x-kpi title="Credit sales" :value="ea_money_short((float)($sales['credit_total'] ?? 0), $ccy)"
                   :sub="number_format((int)($sales['credit_count'] ?? 0)) . ' on account'" color="amber"/>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                        <th class="py-2 pr-3 font-semibold">Invoice</th>
                        <th class="py-2 px-3 font-semibold">Customer</th>
                        <th class="py-2 px-3 font-semibold text-right">Amount</th>
                        <th class="py-2 px-3 font-semibold text-center">Type</th>
                        <th class="py-2 pl-3 font-semibold text-right">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (($sales['rows'] ?? []) as $r)
                        <tr class="border-b border-slate-50">
                            <td class="py-2 pr-3 text-slate-500 whitespace-nowrap">{{ $r['invoice'] }}</td>
                            <td class="py-2 px-3 text-slate-800">{{ $r['customer'] }}</td>
                            <td class="py-2 px-3 text-right tabular-nums text-slate-800">{{ ea_money_short((float) $r['amount'], $ccy) }}</td>
                            <td class="py-2 px-3 text-center">
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full {{ $r['type'] === 'cash' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ ucfirst($r['type']) }}</span>
                            </td>
                            <td class="py-2 pl-3 text-right tabular-nums {{ ($r['open'] ?? 0) > 0 ? 'text-amber-700' : 'text-slate-300' }}">{{ ($r['open'] ?? 0) > 0 ? ea_money_short((float) $r['open'], $ccy) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ── Sandbox 2 · Inventory movements for the day ───────────────────── --}}
<div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
    <h2 class="text-sm font-semibold text-slate-900 mb-1">Inventory movements · {{ $human }}</h2>
    <p class="text-[11px] text-slate-500 mb-4">Completed stock moves on this day. Values at product standard cost.</p>

    @php $inwL = $inventory['inward']['lines'] ?? []; $outL = $inventory['outward']['lines'] ?? []; @endphp
    @if (!$inventory || (($inventory['inward']['qty'] ?? 0) == 0 && ($inventory['outward']['qty'] ?? 0) == 0))
        <div class="text-[13px] text-slate-400 italic py-6 text-center">No inventory movements on this day.</div>
        @if ($inventory)
            <div class="text-center text-[12px] text-slate-500">On-hand inventory value (today, at standard cost): <b>{{ ea_money_short((float) ($inventory['on_hand_value'] ?? 0), $ccy) }}</b></div>
        @endif
    @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
            <x-kpi title="Inward" :value="ea_money_short((float)($inventory['inward']['value'] ?? 0), $ccy)"
                   :sub="number_format((float)($inventory['inward']['qty'] ?? 0), 0) . ' units in'" color="sage"/>
            <x-kpi title="Outward" :value="ea_money_short((float)($inventory['outward']['value'] ?? 0), $ccy)"
                   :sub="number_format((float)($inventory['outward']['qty'] ?? 0), 0) . ' units out'" color="coral"/>
            <x-kpi title="Net movement" :value="ea_money_short((float)($inventory['net_value'] ?? 0), $ccy)"
                   :sub="number_format((float)($inventory['net_qty'] ?? 0), 0) . ' units'" color="slate"/>
            <x-kpi title="On-hand @ std" :value="ea_money_short((float)($inventory['on_hand_value'] ?? 0), $ccy)"
                   sub="value today" color="indigo"/>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            @foreach (['inward' => ['Inward (received)', $inwL, 'emerald'], 'outward' => ['Outward (issued / sold)', $outL, 'rose']] as [$ttl, $lines, $c])
                <div>
                    <h3 class="text-[12px] font-semibold text-slate-700 mb-2">{{ $ttl }}</h3>
                    @if (empty($lines))
                        <div class="text-[12px] text-slate-300 italic">none</div>
                    @else
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                    <th class="py-1.5 pr-3 font-semibold">Product</th>
                                    <th class="py-1.5 px-3 font-semibold text-right">Qty</th>
                                    <th class="py-1.5 pl-3 font-semibold text-right">Value @ std</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $l)
                                    <tr class="border-b border-slate-50">
                                        <td class="py-1.5 pr-3 text-slate-800">{{ $l['name'] }}</td>
                                        <td class="py-1.5 px-3 text-right tabular-nums text-slate-700">{{ number_format((float) $l['qty'], 0) }}</td>
                                        <td class="py-1.5 pl-3 text-right tabular-nums text-slate-700">{{ ea_money_short((float) $l['value'], $ccy) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
