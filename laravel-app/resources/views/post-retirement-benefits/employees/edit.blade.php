@extends('layouts.app')
@section('title', $employee ? 'Edit employee' : 'Add employee')

@section('page-title', $employee ? 'Edit · ' . $employee->full_name : 'Add employee')
@section('content')

@php
    $tenantSlug = request()->route('tenant');
    $action     = $employee
        ? "/t/{$tenantSlug}/post-retirement-benefits/employees/{$employee->id}"
        : "/t/{$tenantSlug}/post-retirement-benefits/employees";
@endphp

<div class="mb-4">
    <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees"
       class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to employees
    </a>
</div>

@if ($errors->any())
    <div class="mb-3 panel p-3 border-l-4 border-rose-400 bg-rose-50">
        <div class="text-[11px] font-semibold text-rose-700 mb-1">Please correct the following:</div>
        <ul class="text-[11.5px] text-rose-800 list-disc pl-4">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="space-y-4 max-w-3xl">
    @csrf

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Identity</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Employee code *</label>
                <input type="text" name="employee_code" required maxlength="64"
                       value="{{ old('employee_code', $employee->employee_code ?? '') }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">Unique within this tenant. Matches your payroll/HRIS reference.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Full name *</label>
                <input type="text" name="full_name" required maxlength="191"
                       value="{{ old('full_name', $employee->full_name ?? '') }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Gender *</label>
                <select name="gender" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                    @php $g = old('gender', $employee->gender ?? 'U'); @endphp
                    <option value="M" @selected($g === 'M')>Male</option>
                    <option value="F" @selected($g === 'F')>Female</option>
                    <option value="U" @selected($g === 'U')>Unknown</option>
                </select>
                <p class="text-[10px] text-slate-500 mt-1">Drives the mortality table (CIMA F for unknown).</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Category *</label>
                <select name="category" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                    @php $cat = old('category', $employee->category ?? ''); @endphp
                    <option value="" disabled @selected($cat === '')>Select…</option>
                    <option value="blue_collar" @selected($cat === 'blue_collar')>Blue collar</option>
                    <option value="supervisor"  @selected($cat === 'supervisor')>Supervisor</option>
                    <option value="manager"     @selected($cat === 'manager')>Manager</option>
                </select>
            </div>
        </div>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Dates &amp; service</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Birth date *</label>
                <input type="date" name="birth_date" required
                       value="{{ old('birth_date', isset($employee) && $employee?->birth_date ? $employee->birth_date->format('Y-m-d') : '') }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Hire date *</label>
                <input type="date" name="hire_date" required
                       value="{{ old('hire_date', isset($employee) && $employee?->hire_date ? $employee->hire_date->format('Y-m-d') : '') }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Termination date</label>
                <input type="date" name="termination_date"
                       value="{{ old('termination_date', isset($employee) && $employee?->termination_date ? $employee->termination_date->format('Y-m-d') : '') }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">Leave blank if still employed.</p>
            </div>
        </div>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Compensation &amp; benefits</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Monthly salary (XAF) *</label>
                <input type="number" step="1" min="0" name="monthly_salary" required
                       value="{{ old('monthly_salary', $employee->monthly_salary ?? '') }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">Base salary used for the 12-month indemnity average.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">CBA</label>
                <select name="cba_key" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white mono">
                    @php $ck = old('cba_key', $employee->cba_key ?? 'commerce'); @endphp
                    @foreach ($cbas as $cba)
                        <option value="{{ $cba['key'] }}" @selected($ck === $cba['key'])>{{ $cba['key'] }} · {{ $cba['name'] }}</option>
                    @endforeach
                </select>
                <p class="text-[10px] text-slate-500 mt-1">Drives the indemnity bracket schedule.</p>
            </div>
            <div class="flex flex-col gap-2 pt-5">
                <label class="flex items-start gap-2 text-[12px] cursor-pointer">
                    <input type="checkbox" name="entitled_to_cnps_pension" value="1"
                           @checked(old('entitled_to_cnps_pension', $employee->entitled_to_cnps_pension ?? true))
                           class="mt-0.5 rounded border-stone-300 text-rose-600 focus:ring-rose-500">
                    <span>
                        <strong>CNPS-eligible</strong>
                        <span class="block text-[10px] text-slate-500">Drives the assumed separation event: retirement (yes) vs termination (no).</span>
                    </span>
                </label>
                <label class="flex items-start gap-2 text-[12px] cursor-pointer">
                    <input type="checkbox" name="active" value="1"
                           @checked(old('active', $employee->active ?? true))
                           class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <span>
                        <strong>Active in plan</strong>
                        <span class="block text-[10px] text-slate-500">Uncheck to exclude from new valuations (preserves history).</span>
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="panel p-5">
        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Notes</label>
        <textarea name="notes" rows="2" maxlength="2000"
                  class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">{{ old('notes', $employee->notes ?? '') }}</textarea>
    </div>

    <div class="flex justify-between items-center">
        <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees" class="text-[12px] text-slate-500 hover:text-slate-900">← Cancel</a>
        <button type="submit"
                class="text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-5 py-2 shadow">
            {{ $employee ? 'Save changes' : 'Add employee' }}
        </button>
    </div>
</form>

@endsection
