@extends('layouts.app')
@section('title', 'Receivables')

@section('header')
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">Collections · {{ ea_last_sync_label() }}</span>
        </div>
        <h1 class="text-2xl font-bold text-[#1e3d44]">Accounts Receivable</h1>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data)
        @php $ccy = $data['currency'] ?? 'USD'; @endphp

        {{-- KPI Strip --}}
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Key Metrics</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <x-kpi title="Total AR" :value="ea_money_short((float)($data['total_ar'] ?? 0), $ccy)" color="brand"/>
                <x-kpi title="Overdue AR" :value="ea_money_short((float)($data['overdue_ar'] ?? 0), $ccy)" color="coral"/>
                <x-kpi title="Overdue %" :value="number_format((float)($data['overdue_rate'] ?? 0), 1).'%'" color="warm"/>
                <x-kpi title="DSO" :value="number_format((float)($data['dso'] ?? 0), 0).' days'" color="slate"/>
            </div>
        </div>

        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-warm-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Risk Indicators</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <x-kpi title="Avg Age" :value="number_format((float)($data['avg_age'] ?? 0), 0).' d'" color="warm"/>
                <x-kpi title="Top-5 Concentration" :value="number_format((float)($data['top5_pct'] ?? 0), 1).'%'" color="brand"/>
                <x-kpi title="% &gt; 60 days" :value="number_format((float)($data['pct_gt_60'] ?? 0), 1).'%'" color="coral"/>
                <x-kpi title="% &gt; 90 days" :value="number_format((float)($data['pct_gt_90'] ?? 0), 1).'%'" color="coral"/>
            </div>
        </div>

        {{-- Top Offender Scorecard --}}
        @if (!empty($data['top_partners']))
            <div class="section-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-1 h-4 rounded bg-coral-400"></div>
                        <div>
                            <h2 class="text-[13px] font-semibold text-[#1e3d44]">Top Offender Scorecard</h2>
                            <p class="text-[11px] text-[#8a8578]">Ranked by outstanding AR + payment behaviour</p>
                        </div>
                    </div>
                    <span class="text-[10px] text-[#a09d95] font-medium">{{ count($data['top_partners']) }} customers</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                <th class="pb-2.5 font-semibold">Customer</th>
                                <th class="pb-2.5 text-right font-semibold">AR</th>
                                <th class="pb-2.5 text-right font-semibold">Overdue</th>
                                <th class="pb-2.5 text-right font-semibold">Share</th>
                                <th class="pb-2.5 font-semibold">Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($data['top_partners'], 0, 8) as $p)
                                @php
                                    $level = $p['rating_level'] ?? 'watch';
                                    $badge = [
                                        'prime'    => 'bg-sage-50 text-sage-700 border-sage-200',
                                        'watch'    => 'bg-warm-50 text-warm-700 border-warm-200',
                                        'risk'     => 'bg-warm-100 text-warm-800 border-warm-300',
                                        'critical' => 'bg-coral-50 text-coral-700 border-coral-200',
                                    ][$level] ?? 'bg-slate-50 text-slate-600 border-slate-200';
                                    $dot = [
                                        'prime'    => 'bg-sage-500',
                                        'watch'    => 'bg-warm-500',
                                        'risk'     => 'bg-warm-600',
                                        'critical' => 'bg-coral-500',
                                    ][$level] ?? 'bg-slate-400';
                                @endphp
                                <tr class="table-row-hover border-b border-[#f0ede7]">
                                    <td class="py-3">
                                        <div class="text-[13px] font-medium text-[#2d3748]">{{ $p['name'] ?? '—' }}</div>
                                        <div class="text-[10px] text-[#a09d95]">{{ $p['rating_reason'] ?? '' }}</div>
                                    </td>
                                    <td class="py-3 text-right tabular-nums text-[13px]">{{ ea_money((float)($p['ar'] ?? 0), $ccy) }}</td>
                                    <td class="py-3 text-right tabular-nums text-[13px] {{ ($p['overdue'] ?? 0) > 0 ? 'text-coral-600' : 'text-[#a09d95]' }}">
                                        {{ ($p['overdue'] ?? 0) > 0 ? ea_money((float) $p['overdue'], $ccy) : '—' }}
                                    </td>
                                    <td class="py-3 text-right tabular-nums text-[13px] text-[#5a6a70]">{{ number_format((float)($p['share_pct'] ?? 0), 1) }}%</td>
                                    <td class="py-3">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[10px] font-semibold {{ $badge }}">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full {{ $dot }}"></span>
                                            {{ $p['rating_label'] ?? 'Watch' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endsection

@push('scripts')
@endpush
