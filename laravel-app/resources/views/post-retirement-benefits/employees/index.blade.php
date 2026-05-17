@extends('layouts.app')
@section('title', 'Employees · Post-Retirement Benefits')

@section('page-title', 'Post-Retirement Benefits · Employees')
@section('content')

@php
    $tenantSlug = request()->route('tenant');
@endphp

{{-- Header row: back link + actions --}}
<div class="flex items-center justify-between mb-4">
    <a href="/t/{{ $tenantSlug }}/post-retirement-benefits"
       class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Post-Retirement Benefits
    </a>
    <div class="flex items-center gap-2">
        <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/import"
           class="text-[12px] font-medium text-slate-700 border border-stone-300 rounded-lg px-3 py-2 hover:bg-stone-50 inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            Import CSV
        </a>
        <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/create"
           class="text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-4 py-2 shadow inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Add employee
        </a>
    </div>
</div>

{{-- Rollup strip --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-3">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Active total</div>
        <div class="text-xl font-bold mono text-slate-900 mt-0.5">{{ $totalHeadcount }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">{{ number_format($totalPayroll, 0) }} XAF / month</div>
    </div>
    @foreach (['blue_collar' => 'Blue collars', 'supervisor' => 'Supervisors', 'manager' => 'Managers'] as $key => $label)
        <div class="panel p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $label }}</div>
            <div class="text-xl font-bold mono text-slate-900 mt-0.5">{{ $headcount[$key] }}</div>
            <div class="text-[10px] text-slate-500 mt-0.5 mono">{{ number_format($payroll[$key], 0) }} XAF</div>
        </div>
    @endforeach
</div>

{{-- Filters --}}
<form method="GET" class="panel p-3 mb-4 flex flex-wrap gap-2 items-end">
    <div class="flex-1 min-w-[200px]">
        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Search</label>
        <input type="text" name="q" value="{{ $q }}" placeholder="Name or code"
               class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Category</label>
        <select name="category" class="border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="">All</option>
            @foreach (['blue_collar' => 'Blue collars', 'supervisor' => 'Supervisors', 'manager' => 'Managers'] as $k => $l)
                <option value="{{ $k }}" @selected($category === $k)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Status</label>
        <select name="status" class="border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="active"   @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
            <option value="all"      @selected($status === 'all')>All</option>
        </select>
    </div>
    <button type="submit" class="text-[12px] font-semibold text-slate-900 bg-stone-100 hover:bg-stone-200 border border-stone-300 rounded-lg px-4 py-2">Filter</button>
</form>

{{-- Table --}}
<div class="panel overflow-hidden">
    <table class="w-full text-[12.5px]">
        <thead class="bg-stone-50 border-b border-stone-200 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="py-2.5 px-3 text-left font-semibold">Code</th>
                <th class="py-2.5 px-3 text-left font-semibold">Name</th>
                <th class="py-2.5 px-3 text-left font-semibold">Category</th>
                <th class="py-2.5 px-3 text-right font-semibold">Salary / mo</th>
                <th class="py-2.5 px-3 text-right font-semibold">Age</th>
                <th class="py-2.5 px-3 text-right font-semibold">Years of service</th>
                <th class="py-2.5 px-3 text-center font-semibold">CNPS</th>
                <th class="py-2.5 px-3 text-center font-semibold">Status</th>
                <th class="py-2.5 px-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @forelse ($employees as $e)
                <tr class="hover:bg-stone-50/60">
                    <td class="py-2 px-3 mono text-slate-600">{{ $e->employee_code }}</td>
                    <td class="py-2 px-3 text-slate-900 font-medium">{{ $e->full_name }}
                        @if ($e->gender !== 'U')
                            <span class="text-[10px] text-slate-400 mono ml-1">({{ $e->gender }})</span>
                        @endif
                    </td>
                    <td class="py-2 px-3 text-slate-600 capitalize">{{ str_replace('_', ' ', $e->category) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format((float) $e->monthly_salary, 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format($e->ageAt(), 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format($e->serviceYearsAt(), 1) }}</td>
                    <td class="py-2 px-3 text-center">
                        <span class="text-[10px] mono px-1.5 py-0.5 rounded {{ $e->entitled_to_cnps_pension ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-stone-100 text-slate-600 border border-stone-200' }}">
                            {{ $e->entitled_to_cnps_pension ? 'yes' : 'no' }}
                        </span>
                    </td>
                    <td class="py-2 px-3 text-center">
                        @if ($e->active)
                            <span class="text-[10px] mono px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">active</span>
                        @else
                            <span class="text-[10px] mono px-1.5 py-0.5 rounded bg-stone-100 text-slate-500 border border-stone-200">inactive</span>
                        @endif
                    </td>
                    <td class="py-2 px-3 text-right whitespace-nowrap">
                        <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/{{ $e->id }}/edit"
                           class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-900 mr-2">Edit</a>
                        <form method="POST" action="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/{{ $e->id }}/delete"
                              class="inline" onsubmit="return confirm('Remove {{ $e->full_name }} from the plan?')">
                            @csrf
                            <button type="submit" class="text-[11px] font-semibold text-rose-600 hover:text-rose-900">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="py-8 px-3 text-center text-slate-500 text-[12.5px]">
                        @if ($q !== '' || $category)
                            No employees match the current filter. <a href="?" class="text-indigo-600 underline">Clear filter</a>
                        @else
                            No employees in the plan yet. <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/create" class="text-indigo-600 underline">Add the first one</a>
                            or <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/import" class="text-indigo-600 underline">import a CSV</a>.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($employees->hasPages())
    <div class="mt-4">{{ $employees->links() }}</div>
@endif

@endsection
