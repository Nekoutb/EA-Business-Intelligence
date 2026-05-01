@extends('layouts.app')
@section('title', 'Cashflow Forecast')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $year }} · Cameroon · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Cashflow Forecast</h1>
            <div class="text-[11px] text-[#7c7466] mt-1">Direct-method monthly cashflow — built from actual cash movements (Class 5) categorised by the counter-account (customers / suppliers / employees / taxes / capex / financing). Closed months show actual figures; remaining months are SMLY forecast. Figures in million XAF.</div>
        </div>
        <form method="get" class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Fiscal Year</label>
                <select name="year"
                        class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none min-w-[7rem]"
                        onchange="this.form.submit()">
                    @php $thisYear = (int) date('Y'); @endphp
                    @for ($y = $thisYear + 1; $y >= $thisYear - 3; $y--)
                        <option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
        </form>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6"><strong>Could not fetch cashflow:</strong> {{ $error }}</div>
    @endif

    @if (!$data || empty($data['lines']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No cashflow data — check that the GL is reachable and that posted moves exist for {{ $year }}.</p>
        </div>
    @else
        @php
            $months = $data['months'];
            $lines  = $data['lines'];
            $ccy    = $data['currency'] ?? 'XAF';
            $fmtM = function ($v) {
                if ($v === null) return '—';
                $m = ((float) $v) / 1_000_000.0;
                if (abs($m) < 0.5) return '0';
                return $m < 0 ? '(' . number_format(-$m, 0) . ')' : number_format($m, 0);
            };
            $actualText  = 'text-[#5d8067]';
            $budgetText  = 'text-[#6B2D2F]';
            $actualBadge = 'bg-[#e9f0eb] text-[#3d5e48] border-[#cbdbd0]';
            $budgetBadge = 'bg-[#f4e2e3] text-[#6B2D2F] border-[#e0c5c7]';
        @endphp

        <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5]">
                <div class="text-[10.5px] text-[#7c7466] flex items-center gap-3 flex-wrap">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-[#e9f0eb] border border-[#cbdbd0]"></span><span class="font-semibold {{ $actualText }}">Actual</span></span>
                    <span class="text-[#cbc7be]">·</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-[#f4e2e3] border border-[#e0c5c7]"></span><span class="font-semibold {{ $budgetText }}">Forecast</span></span>
                </div>
                <div class="text-[10.5px] text-[#7c7466]">Opening cash {{ $year }}: <strong>{{ $fmtM($data['opening_cash'] ?? 0) }}</strong> million {{ $ccy }} · Direct method (SYSCOHADA Class 5)</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[11.5px]" style="min-width: 1100px;">
                    <thead class="bg-white">
                        <tr class="border-b border-[#e8e4de]">
                            <th class="text-left py-1.5 pl-4 pr-2 sticky left-0 bg-white z-10 w-[20rem]"><span class="text-[9.5px] uppercase tracking-wider text-[#8a8578] font-medium">Cashflow Line</span></th>
                            @foreach ($months as $m)
                                @php $isActual = !$m['is_forecast']; @endphp
                                <th class="py-1.5 px-2 text-center"><span class="inline-flex text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 border font-semibold {{ $isActual ? $actualBadge : $budgetBadge }}">{{ $isActual ? 'Actual' : 'Fcst' }}</span></th>
                            @endforeach
                            <th class="py-1.5 px-2 text-center"><span class="inline-flex text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 border font-semibold bg-slate-100 text-slate-700 border-slate-200">Year</span></th>
                        </tr>
                        <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10 w-[20rem]"><span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">Line · {{ $ccy }} (millions)</span></th>
                            @foreach ($months as $m)
                                @php $isActual = !$m['is_forecast']; @endphp
                                <th class="py-2 px-2 text-center text-[11px] tabular-nums {{ $isActual ? $actualText : $budgetText }} font-bold">{{ $m['label'] }}</th>
                            @endforeach
                            <th class="py-2 px-2 text-center text-[11px] font-bold text-[#1e3d44]">{{ $year }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($lines as $line)
                            @php
                                $isSubtotal = $line['kind'] === 'subtotal';
                                $bg   = $isSubtotal ? 'bg-[#fcfbf8]' : 'bg-white';
                                $font = $isSubtotal ? 'font-bold' : 'font-medium';
                                $total = array_sum($line['monthly']);
                            @endphp
                            <tr class="{{ $bg }} hover:bg-[#faf8f5] transition-colors">
                                <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 {{ $bg }} z-10 w-[20rem] {{ $font }} text-[#1e3d44]">{{ $line['label'] }}</td>
                                @foreach ($months as $m)
                                    @php
                                        $isActual = !$m['is_forecast'];
                                        $colour   = $isActual ? $actualText : $budgetText;
                                        $val      = $line['monthly'][$m['num']] ?? null;
                                    @endphp
                                    <td class="text-right tabular-nums px-2 py-1.5 {{ $colour }} {{ $font }}">{{ $fmtM($val) }}</td>
                                @endforeach
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $font }} text-[#1e3d44] border-l border-[#e8e4de]">{{ $fmtM($total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-2.5 border-t border-[#e8e4de] bg-[#faf8f5] text-[10.5px] text-[#7c7466]">
                Direct-method cashflow. Each line tracks actual cash movements (Class 5 — banques + caisse) categorised by the dominant counter-account on the move: customers (41) / suppliers (40) / employees (42, 64) / taxes (43, 44) / income tax (89) / capex (Class 2) / financing (Class 1). Forecast = SMLY (same month last year) per bucket with trailing-3-month fallback.
            </div>
        </div>
    @endif
@endsection
