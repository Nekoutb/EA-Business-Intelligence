@extends('layouts.app')
@section('title', 'Import employees · Post-Retirement Benefits')

@section('page-title', 'Import employees from CSV')
@section('content')

@php
    $tenantSlug = request()->route('tenant');
    $r = session('import_result');
@endphp

<div class="mb-4">
    <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees"
       class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to employees
    </a>
</div>

@if (session('flash'))
    @php $flash = session('flash'); $cls = match($flash['type'] ?? '') {
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-800',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-800',
        default   => 'border-rose-300 bg-rose-50 text-rose-800',
    }; @endphp
    <div class="mb-4 panel p-3 border-l-4 {{ $cls }}">{{ $flash['msg'] ?? '' }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 max-w-6xl">

    {{-- Left: upload form --}}
    <div class="lg:col-span-2 panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Upload CSV</h2>

        <form method="POST" action="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/import"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">CSV file *</label>
                <input type="file" name="file" required accept=".csv,text/csv"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                <p class="text-[10.5px] text-slate-500 mt-1">Max 5 MB. UTF-8 encoded. First row must be the column header.</p>
            </div>

            @if ($errors->any())
                <div class="text-[11.5px] text-rose-700">
                    @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div class="flex items-center justify-between">
                <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees/import/template"
                   class="text-[12px] font-medium text-indigo-600 hover:text-indigo-800 inline-flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                    Download CSV template
                </a>
                <button type="submit"
                        class="text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-5 py-2 shadow">
                    Import
                </button>
            </div>
        </form>

        @if ($r && !empty($r['errors']))
            <div class="mt-5 panel p-3 border-l-4 border-amber-400 bg-amber-50/50">
                <div class="text-[11px] uppercase tracking-widest text-amber-800 font-semibold mb-2">
                    {{ count($r['errors']) }} row(s) skipped
                </div>
                <table class="w-full text-[11px]">
                    <thead class="text-slate-500 border-b border-amber-200">
                        <tr>
                            <th class="text-left py-1 pr-2">Row</th>
                            <th class="text-left py-1 pr-2">Code</th>
                            <th class="text-left py-1">Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100">
                        @foreach ($r['errors'] as $err)
                            <tr>
                                <td class="py-1 pr-2 mono text-slate-600">{{ $err['row'] }}</td>
                                <td class="py-1 pr-2 mono text-slate-600">{{ $err['code'] ?: '—' }}</td>
                                <td class="py-1 text-rose-700">{{ $err['error'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Right: column reference --}}
    <div class="panel p-5 text-[11.5px]">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Column reference</h2>
        <div class="space-y-3">
            <div>
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Required</div>
                <ul class="space-y-1.5">
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">employee_code</code> — unique within tenant</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">full_name</code></li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">birth_date</code> — YYYY-MM-DD</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">hire_date</code> — YYYY-MM-DD</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">category</code> — <span class="mono">blue_collar</span> / <span class="mono">supervisor</span> / <span class="mono">manager</span></li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">monthly_salary</code> — number, no separators</li>
                </ul>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Optional</div>
                <ul class="space-y-1.5">
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">gender</code> — M / F / U (default U)</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">termination_date</code> — empty if active</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">entitled_to_cnps_pension</code> — true/false (default true)</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">cba_key</code> — default <span class="mono">commerce</span></li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">active</code> — default true</li>
                    <li><code class="bg-stone-100 px-1.5 py-0.5 rounded mono text-[10.5px]">notes</code></li>
                </ul>
            </div>
            <div class="pt-2 border-t border-stone-200 text-[10.5px] text-slate-500 leading-snug">
                Existing rows (same <span class="mono">employee_code</span> in this tenant) are <strong>updated</strong>. New ones are inserted. The import never deletes — to remove an employee, use the table.
            </div>
        </div>
    </div>
</div>

@endsection
