@extends('layouts.app')
@section('title', 'Valuations · Post-Retirement Benefits')

@section('page-title', 'Post-Retirement Benefits · Valuations history')
@section('content')

@php $tenantSlug = request()->route('tenant'); @endphp

<div class="mb-4">
    <a href="/t/{{ $tenantSlug }}/post-retirement-benefits"
       class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Post-Retirement Benefits
    </a>
</div>

<div class="panel overflow-hidden">
    <table class="w-full text-[12.5px]">
        <thead class="bg-stone-50 border-b border-stone-200 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="py-2.5 px-3 text-left font-semibold">Valuation date</th>
                <th class="py-2.5 px-3 text-center font-semibold">Status</th>
                <th class="py-2.5 px-3 text-right font-semibold">PBO closing</th>
                <th class="py-2.5 px-3 text-right font-semibold">Funded status</th>
                <th class="py-2.5 px-3 text-right font-semibold">NPBC</th>
                <th class="py-2.5 px-3 text-right font-semibold">Headcount</th>
                <th class="py-2.5 px-3 text-right font-semibold">Generated</th>
                <th class="py-2.5 px-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @forelse ($valuations as $v)
                <tr class="hover:bg-stone-50/60">
                    <td class="py-2 px-3 mono text-slate-900 font-medium">{{ $v->valuation_date->format('d M Y') }}</td>
                    <td class="py-2 px-3 text-center">
                        <span class="text-[10px] mono px-1.5 py-0.5 rounded {{ $v->status === 'finalized' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">{{ $v->status }}</span>
                    </td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format((float) $v->pbo_closing, 0) }}</td>
                    <td class="py-2 px-3 text-right mono {{ $v->funded_status >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format((float) $v->funded_status, 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format((float) $v->net_periodic_benefit_cost, 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ $v->headcount_blue_collar + $v->headcount_supervisor + $v->headcount_manager }}</td>
                    <td class="py-2 px-3 text-right text-[11px] text-slate-500">{{ $v->created_at->diffForHumans() }}</td>
                    <td class="py-2 px-3 text-right">
                        <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/valuations/{{ $v->id }}"
                           class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-900">View →</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="py-8 text-center text-[12px] text-slate-500">
                        No valuations have been run yet. Use the <strong>Run valuation</strong> button on the landing page.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($valuations->hasPages())
    <div class="mt-4">{{ $valuations->links() }}</div>
@endif

@endsection
