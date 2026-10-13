@extends('layouts.app')
@section('title', 'Productivity settings')

@section('page-title', 'Productivity settings')
@section('content')
@php
    $thisYear = (int) date('Y');
    $dis  = $canEdit ? '' : 'disabled';
    $inp  = 'w-20 border border-[#e2ddd4] rounded px-2 py-1 text-[12px] text-right tabular-nums ' . ($canEdit ? 'bg-white' : 'bg-[#f3f1ec] text-[#9a958b] cursor-not-allowed');
    $th   = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
    $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
@endphp

@unless ($canEdit)
    <div class="bg-[#f5f2ec] border border-[#e8e4de] text-[#7c7466] rounded-lg p-3 text-[12px] mb-4">
        These settings can only be changed by the administrator. They are shown read-only.
    </div>
@endunless

@if (!$manual && count($rows))
    <div class="bg-warm-50 border border-warm-200 text-[#7a5a1a] rounded-lg p-3 text-[12px] mb-4">
        <b>Manual figures are saved but switched off.</b> They are not used in any computation until "Use manual figures" is ticked and saved.
    </div>
@endif

<form method="POST" action="{{ tenant_url('/hr/productivity-settings') }}">
    @csrf
    <input type="hidden" name="year" value="{{ $year }}">

    <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-4 {{ $canEdit ? '' : 'opacity-75' }}">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-sm font-semibold text-[#1e3d44]">Manual figures</h2>
                <p class="text-[11px] text-[#7c7466] mt-1 max-w-2xl">When on, the number of employees and the number of files entered below replace the system counts for those months in every computation that uses them: HR Productivity, the dashboard's Revenue / file and Cost / file, Profitability per file and the Revenue streams per file. Blank months keep the system count.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-[12px] font-semibold text-[#1e3d44] {{ $canEdit ? 'cursor-pointer' : 'cursor-not-allowed' }}">
                <input type="checkbox" name="manual" value="1" {{ $manual ? 'checked' : '' }} {{ $dis }} class="rounded border-[#cbc7be] text-brand-600 focus:ring-brand-500">
                Use manual figures
            </label>
        </div>

        <div class="mt-4">
            <label class="block text-[11px] font-semibold text-[#5a6a70] mb-1" for="natures">Nature of file (comma-separated)</label>
            <input id="natures" name="natures" type="text" value="{{ implode(', ', $natures) }}" {{ $dis }}
                   placeholder="e.g. Import maritime, Import aérien, Export, Transit"
                   class="w-full max-w-2xl border border-[#e2ddd4] rounded-lg px-3 py-2 text-[12px] {{ $canEdit ? 'bg-white' : 'bg-[#f3f1ec] text-[#9a958b] cursor-not-allowed' }}">
            <p class="text-[10.5px] text-[#a09d95] mt-1">Each nature gets its own column below. When natures are filled and the total is left blank, the month's files = the sum of the natures. Save once after changing the list to see its columns.</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm mb-4 overflow-x-auto {{ $canEdit ? '' : 'opacity-75' }}">
        <div class="flex items-center justify-between gap-3 px-5 pt-4 pb-2 flex-wrap">
            <h2 class="text-sm font-semibold text-[#1e3d44]">Monthly figures · {{ $year }}</h2>
            <div class="inline-flex items-center gap-1 bg-white border border-[#e8e4de] rounded-lg p-1">
                @foreach (range($thisYear - 1, $thisYear) as $y)
                    <a href="{{ tenant_url('/hr/productivity-settings?year=' . $y) }}"
                       class="px-2.5 py-1 text-[11px] font-semibold rounded {{ (int) $year === $y ? 'bg-brand-600 text-white' : 'text-[#7c7466] hover:bg-[#f5f2ec]' }}">{{ $y }}</a>
                @endforeach
            </div>
        </div>
        <table class="w-full">
            <thead class="bg-[#faf9f6]"><tr>
                <th class="{{ $th }} text-left pl-5">Month</th>
                <th class="{{ $th }} text-right">Employees</th>
                <th class="{{ $th }} text-right">Total files</th>
                @foreach ($natures as $nat)<th class="{{ $th }} text-right">{{ $nat }}</th>@endforeach
                <th class="{{ $th }} text-right pr-5">Files used</th>
            </tr></thead>
            <tbody>
            @foreach ($months as $i => $label)
                @php $mm = $i + 1; $ym = sprintf('%04d-%02d', $year, $mm); $r = $rows[$ym] ?? null; @endphp
                <tr class="border-t border-[#f1ede5]">
                    <td class="px-2 py-1.5 pl-5 text-[12px] font-semibold text-[#2d3748]">{{ $label }}</td>
                    <td class="px-2 py-1.5 text-right"><input type="number" min="0" step="1" name="m[{{ $mm }}][employees]" value="{{ $r['employees'] ?? '' }}" {{ $dis }} class="{{ $inp }}"></td>
                    <td class="px-2 py-1.5 text-right"><input type="number" min="0" step="1" name="m[{{ $mm }}][files]" value="{{ $r['files'] ?? '' }}" {{ $dis }} class="{{ $inp }}"></td>
                    @foreach ($natures as $ni => $nat)
                        <td class="px-2 py-1.5 text-right"><input type="number" min="0" step="1" name="m[{{ $mm }}][nat][{{ $ni }}]" value="{{ $r['natures'][$nat] ?? '' }}" {{ $dis }} class="{{ $inp }}"></td>
                    @endforeach
                    <td class="px-2 py-1.5 pr-5 text-right text-[12px] tabular-nums text-[#5a6a70]">{{ ($r['files_effective'] ?? null) !== null ? number_format($r['files_effective']) : 'system' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($canEdit)
        <div class="flex justify-end">
            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white font-semibold px-4 py-2 rounded-lg text-sm shadow">Save settings</button>
        </div>
    @endif
</form>
@endsection
