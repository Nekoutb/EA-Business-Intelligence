@extends('layouts.app')
@section('title', 'Budget Upload')

@section('page-title', 'Budget Upload')
@section('content')
    @if (session('flash'))
        @php $flash = session('flash'); @endphp
        <div class="rounded-xl p-3 text-xs mb-4 border
            {{ ($flash['type'] ?? '') === 'success' ? 'bg-sage-50 border-sage-200 text-sage-800'
             : (($flash['type'] ?? '') === 'error'  ? 'bg-coral-50 border-coral-200 text-coral-800'
             :                                       'bg-warm-50 border-warm-200 text-warm-800') }}">
            {{ $flash['msg'] ?? '' }}
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-3 text-xs mb-4">
            @foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        {{-- Upload form --}}
        <div class="lg:col-span-2 bg-white border border-[#e8e4de] rounded-xl p-5">
            <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Upload budget for {{ $year }}</h3>
            <form method="POST" action="{{ tenant_url('/budget-upload') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">

                <div>
                    <label class="block text-[11px] font-medium text-[#5a6a70] mb-1.5">Excel or CSV file (max 5 MB)</label>
                    <input type="file" name="file" accept=".xlsx,.csv" required
                           class="block w-full text-xs text-[#1e3d44] border border-[#e8e4de] rounded-lg p-2 bg-white file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-brand-600 file:text-white file:text-[11px] file:cursor-pointer hover:file:bg-brand-700">
                    <p class="text-[10.5px] text-[#7c7466] mt-1.5 leading-snug">
                        First row should be a header: <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">P&amp;L Line · Jan · Feb · … · Dec</code>.
                        One row per line (Revenue, Direct operating costs, Operating expenses, Depreciation &amp; amortisation, Net financial result, Income tax).
                        Values may be in <strong>millions XAF</strong> (e.g. <code class="text-[10px]">45</code>) or absolute XAF (e.g. <code class="text-[10px]">45000000</code>) — auto-detected.
                        Cost lines may be negative or in parentheses.
                    </p>
                </div>

                <div class="flex items-center gap-3 pt-1 flex-wrap">
                    <button type="submit" class="text-xs font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white">
                        Upload &amp; save
                    </button>
                    <a href="{{ tenant_url('/budget-upload/template?year=' . $year) }}"
                       class="text-[11px] font-medium text-brand-700 hover:text-brand-900 border border-brand-200 hover:border-brand-400 rounded px-3 py-1.5 inline-flex items-center gap-1.5">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V8a2 2 0 012-2h6l2 2h5a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                        Download blank template ({{ $year }}.xlsx)
                    </a>
                </div>
            </form>
        </div>

        {{-- Current state --}}
        <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
            <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Current budget — {{ $year }}</h3>
            @if ($summary)
                <div class="text-[11.5px] text-[#5a6a70] space-y-1">
                    <div><strong class="text-[#1e3d44]">File:</strong> {{ $summary['source_filename'] ?: '—' }}</div>
                    <div><strong class="text-[#1e3d44]">Uploaded by:</strong> {{ $summary['uploaded_by_name'] }}</div>
                    @if (!empty($summary['uploaded_at']))
                        <div><strong class="text-[#1e3d44]">When:</strong> {{ \Carbon\Carbon::parse($summary['uploaded_at'])->diffForHumans() }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ tenant_url('/budget-upload/delete') }}" class="mt-3"
                      onsubmit="return confirm('Delete the budget for {{ $year }}? You can re-upload at any time.')">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}">
                    <button type="submit" class="text-[11px] font-medium text-coral-700 hover:text-coral-900 border border-coral-200 hover:border-coral-400 rounded px-3 py-1.5">
                        Delete this year's budget
                    </button>
                </form>
            @else
                <div class="text-[11.5px] text-[#7c7466] leading-relaxed">
                    No budget uploaded for {{ $year }} yet.<br>
                    The P&amp;L Forecast Budget column is currently using <strong>Prior Year × growth factor</strong>.
                </div>
            @endif
        </div>
    </div>

    @if ($summary && !empty($summary['data']))
        {{-- Preview the saved budget --}}
        @php
            $fmtM = function ($v) {
                if ($v === null) return '—';
                $m = ((float) $v) / 1_000_000.0;
                if (abs($m) < 0.5) return '0';
                return $m < 0 ? '(' . number_format(-$m, 0) . ')' : number_format($m, 0);
            };
            $monthsHdr = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        @endphp
        <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
            <div class="px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5] text-[10.5px] text-[#7c7466]">
                Saved budget · figures in <strong>million XAF</strong> · negatives in parentheses
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[11.5px]" style="min-width: 980px;">
                    <thead class="bg-[#fcfbf8]">
                        <tr class="border-b border-[#cbc7be]">
                            <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10 w-[18rem]">
                                <span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">P&amp;L Line</span>
                            </th>
                            @foreach ($monthsHdr as $m)
                                <th class="py-2 px-2 text-center text-[11px] font-bold text-[#1e3d44]">{{ $m }}</th>
                            @endforeach
                            <th class="py-2 px-2 text-center text-[11px] font-bold text-[#1e3d44]">Year</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($lineLabels as $key => $label)
                            @php
                                $vec = (array) ($summary['data'][$key] ?? []);
                                $year_total = array_sum($vec);
                            @endphp
                            <tr class="hover:bg-[#faf8f5]">
                                <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 bg-white z-10 w-[18rem] font-medium text-[#1e3d44]">{{ $label }}</td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-right tabular-nums px-2 py-1.5 text-[#5a6a70]">{{ $fmtM($vec[$m] ?? $vec[(string) $m] ?? null) }}</td>
                                @endfor
                                <td class="text-right tabular-nums px-2 py-1.5 font-bold text-[#1e3d44] border-l border-[#e8e4de]">{{ $fmtM($year_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
