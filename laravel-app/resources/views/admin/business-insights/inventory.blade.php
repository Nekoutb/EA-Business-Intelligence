@extends('admin.layout')
@section('title', 'Data Inventory · ' . $tenant->name)

@section('content')
<div class="mb-4">
    <a href="/admin/business-insights" class="text-[12px] text-slate-500 hover:text-slate-900 inline-flex items-center gap-1">
        ← Business Insights
    </a>
</div>

<div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
    <div class="flex items-start justify-between gap-4 mb-3">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Data Inventory</div>
            <h1 class="text-xl font-bold text-slate-900">{{ $tenant->brand ?: $tenant->name }}</h1>
            <p class="text-[11px] text-slate-500 mt-1 mono">
                {{ $tenant->slug }} ·
                @if ($inventory) generated {{ $inventory['generated_at'] }} @endif
            </p>
        </div>
        <a href="?refresh=1" class="text-[11px] font-medium text-rose-700 border border-rose-300 rounded-lg px-3 py-2 hover:bg-rose-50">⟳ Refresh</a>
    </div>

    <p class="text-[12px] text-slate-600 leading-relaxed max-w-3xl">
        This page audits what financial data the tenant actually has in their Odoo instance — domain by
        domain. Use the inventory to decide which on-demand management reports to generate first: the
        <strong>Generate report</strong> buttons next to each domain are only clickable when the underlying
        data exists.
    </p>
</div>

@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-4 text-sm mb-5">
        <strong>Inventory failed:</strong> {{ $error }}
        <div class="text-[11px] mt-1">Most common cause: Odoo connection unreachable. Check
            <a href="/admin/tenants/{{ $tenant->id }}/settings" class="underline">settings</a>.
        </div>
    </div>
@endif

@if ($inventory)
    {{-- Summary cards --}}
    @php $sum = $inventory['summary'] ?? []; @endphp
    <div class="grid grid-cols-4 gap-3 mb-5">
        <div class="bg-white border border-emerald-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Available</div>
            <div class="text-2xl font-bold mono text-emerald-700 mt-1">{{ $sum['available'] ?? 0 }}</div>
            <div class="text-[10px] text-slate-500 mt-1">ready for reports</div>
        </div>
        <div class="bg-white border border-amber-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Sparse</div>
            <div class="text-2xl font-bold mono text-amber-700 mt-1">{{ $sum['sparse'] ?? 0 }}</div>
            <div class="text-[10px] text-slate-500 mt-1">limited data</div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Empty</div>
            <div class="text-2xl font-bold mono text-slate-500 mt-1">{{ $sum['empty'] ?? 0 }}</div>
            <div class="text-[10px] text-slate-500 mt-1">model present, no records</div>
        </div>
        <div class="bg-white border border-rose-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Unavailable</div>
            <div class="text-2xl font-bold mono text-rose-700 mt-1">{{ $sum['unavailable'] ?? 0 }}</div>
            <div class="text-[10px] text-slate-500 mt-1">module not installed / query failed</div>
        </div>
    </div>

    {{-- Inventory table --}}
    <div class="bg-white border border-stone-200 rounded-xl overflow-hidden mb-5">
        <table class="w-full text-[12.5px]">
            <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                <tr>
                    <th class="text-left py-2.5 px-3 font-semibold">Domain</th>
                    <th class="text-left py-2.5 px-3 font-semibold">Status</th>
                    <th class="text-right py-2.5 px-3 font-semibold">Records</th>
                    <th class="text-left py-2.5 px-3 font-semibold">Date range</th>
                    <th class="text-left py-2.5 px-3 font-semibold">Sources</th>
                    <th class="text-left py-2.5 px-3 font-semibold">Recommended reports</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($inventory['domains'] as $d)
                    @php
                        $statusBadge = match ($d['status']) {
                            'available'   => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                            'sparse'      => 'bg-amber-100 text-amber-700 border-amber-200',
                            'empty'       => 'bg-stone-100 text-slate-600 border-stone-200',
                            'unavailable' => 'bg-rose-100 text-rose-700 border-rose-200',
                            default       => 'bg-stone-100 text-slate-600 border-stone-200',
                        };
                    @endphp
                    <tr class="hover:bg-stone-50/40">
                        <td class="py-2.5 px-3 align-top">
                            <div class="font-semibold text-slate-900">{{ $d['label'] }}</div>
                            <div class="text-[10.5px] text-slate-500 mono mt-0.5">{{ $d['notes'] }}</div>
                        </td>
                        <td class="py-2.5 px-3 align-top">
                            <span class="text-[10px] uppercase tracking-widest font-bold border rounded px-1.5 py-0.5 {{ $statusBadge }}">{{ $d['status'] }}</span>
                        </td>
                        <td class="py-2.5 px-3 text-right mono align-top">
                            {{ number_format($d['record_count']) }}
                        </td>
                        <td class="py-2.5 px-3 align-top text-[11px] text-slate-600 mono">
                            @if ($d['first_date'] && $d['last_date'])
                                <div>{{ $d['first_date'] }}</div>
                                <div class="text-slate-400">→ {{ $d['last_date'] }}</div>
                                @if ($d['months_span'] > 0)
                                    <div class="text-[10px] text-slate-500 mt-0.5">{{ $d['months_span'] }} months</div>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-3 align-top text-[10.5px] text-slate-600 mono">
                            @foreach ($d['sources'] as $s)
                                <div>{{ $s }}</div>
                            @endforeach
                            @if (empty($d['sources']))<span class="text-slate-400">—</span>@endif
                        </td>
                        <td class="py-2.5 px-3 align-top">
                            @if (empty($d['reports']))
                                <span class="text-[11px] text-slate-400 italic">No reports — insufficient data</span>
                            @else
                                <div class="space-y-1">
                                    @foreach ($d['reports'] as $r)
                                        <div class="flex items-center gap-2 text-[11px]">
                                            <span class="text-slate-700">• {{ $r['label'] }}</span>
                                            @php
                                                // Report keys with a live generator. Maps domain+key → URL.
                                                $reportUrl = match (true) {
                                                    $d['key'] === 'revenue'   && $r['key'] === 'monthly_trend' => "/admin/business-insights/{$tenant->id}/reports/revenue",
                                                    $d['key'] === 'inventory' && $r['key'] === 'stock_value'   => "/admin/business-insights/{$tenant->id}/reports/stock-value",
                                                    $d['key'] === 'inventory' && $r['key'] === 'slow_movers'   => "/admin/business-insights/{$tenant->id}/reports/slow-movers",
                                                    default => null,
                                                };
                                            @endphp
                                            @if ($reportUrl)
                                                <a href="{{ $reportUrl }}"
                                                   class="text-[10px] font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded px-2 py-0.5 ml-auto">
                                                    Generate →
                                                </a>
                                            @else
                                                <span class="text-[9px] text-slate-400 ml-auto italic">Coming soon</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Errors block --}}
    @if (!empty($inventory['errors']))
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-[11px] text-amber-800">
            <strong>{{ count($inventory['errors']) }} domain audit warning(s):</strong>
            <ul class="list-disc pl-4 mt-1">
                @foreach ($inventory['errors'] as $e)<li class="mono">{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif
@endif

@endsection
