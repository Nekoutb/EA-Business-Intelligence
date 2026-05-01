@extends('layouts.app')
@section('title', 'P&L Forecast')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">
                    {{ $year }} · Cameroon · {{ ea_last_sync_label() }}
                </span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">P&amp;L Forecast</h1>
            <div class="text-[11px] text-[#7c7466] mt-1">
                @if ($view === 'full_year')
                    Full-year monthly P&amp;L. Closed months show <strong class="text-[#5d8067]">Actual</strong>; current and future months show <strong class="text-[#6B2D2F]">Budget</strong> (forecast). Year total compared against full-year Budget at the bottom.
                @elseif ($view === 'mtd')
                    Single-month comparison: Prior Year · Current Year · Forecast · Budget. Variances (CY vs PY, Forecast vs Budget) shown alongside.
                @elseif ($view === 'ytd')
                    Year-to-Date comparison: PY YTD · CY YTD · Full-Year Forecast · Full-Year Budget, with variances.
                @endif
                Figures in million XAF.
            </div>
        </div>

        {{-- Filters: View mode + Year + (Month for MTD) --}}
        <form method="get" class="flex items-end gap-2 flex-wrap">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">View</label>
                <select name="view"
                        class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none"
                        onchange="this.form.submit()">
                    <option value="full_year" {{ $view === 'full_year' ? 'selected' : '' }}>Full Year</option>
                    <option value="ytd"       {{ $view === 'ytd'       ? 'selected' : '' }}>YTD</option>
                    <option value="mtd"       {{ $view === 'mtd'       ? 'selected' : '' }}>MTD</option>
                </select>
            </div>
            @if ($view === 'mtd')
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Month</label>
                    <select name="month"
                            class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none"
                            onchange="this.form.submit()">
                        @foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $i => $name)
                            <option value="{{ $i + 1 }}" {{ ($i + 1) === $month ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Year</label>
                <select name="year"
                        class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none"
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
    @if (session('flash'))
        @php $flash = session('flash'); @endphp
        <div class="rounded-xl p-3 text-xs mb-4 border
            {{ ($flash['type'] ?? '') === 'success' ? 'bg-sage-50 border-sage-200 text-sage-800' : 'bg-warm-50 border-warm-200 text-warm-800' }}">
            {{ $flash['msg'] ?? '' }}
        </div>
    @endif

    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not fetch P&amp;L:</strong> {{ $error }}
        </div>
    @endif

    @if (!$data || empty($data['lines']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No P&amp;L lines available — check that the GL is reachable and that posted moves exist.</p>
        </div>
    @else

      @php
          // Number formatter — millions XAF, no decimals, negatives in parens
          $fmtM = function ($v) {
              if ($v === null) return '—';
              if (!is_finite((float) $v)) return '—';
              $m = ((float) $v) / 1_000_000.0;
              if (abs($m) < 0.5) return '0';
              return $m < 0 ? '(' . number_format(-$m, 0) . ')' : number_format($m, 0);
          };
          $fmtPct  = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . '%';
          $fmtPctVar = function ($v) {
              if ($v === null) return '—';
              return ($v >= 0 ? '+' : '') . number_format((float) $v, 1) . '%';
          };
          $fmtMVar = function ($v) {
              if ($v === null) return '—';
              $m = ((float) $v) / 1_000_000.0;
              if (abs($m) < 0.5) return '0';
              return ($m >= 0 ? '+' : '') . number_format($m, 0);
          };
          $varColour = function ($v, bool $higherIsBetter = true) {
              if ($v === null || $v == 0) return 'text-[#7c7466]';
              $good = $higherIsBetter ? $v > 0 : $v < 0;
              return $good ? 'text-[#5d8067]' : 'text-[#6B2D2F]';
          };

          $actualText = 'text-[#5d8067]';
          $budgetText = 'text-[#6B2D2F]';
          $actualBadge = 'bg-[#e9f0eb] text-[#3d5e48] border-[#cbdbd0]';
          $budgetBadge = 'bg-[#f4e2e3] text-[#6B2D2F] border-[#e0c5c7]';

          $kindStyle = [
              'leaf'     => ['bg' => 'bg-white',     'font' => 'font-medium',           'border' => 'border-[#f1ede5]'],
              'subtotal' => ['bg' => 'bg-[#fcfbf8]', 'font' => 'font-bold',             'border' => 'border-[#e8e4de]'],
              'margin'   => ['bg' => 'bg-[#fcfbf8]', 'font' => 'font-semibold italic',  'border' => 'border-[#f1ede5]'],
          ];

          // For margin rows, "higher is better" is true for positive
          // margins (most P&L lines). We'll just leave variance colour
          // neutral for margins — too easy to mis-read.
      @endphp

      {{-- =============================================================
           FULL YEAR VIEW — 12-month grid (existing layout, retained)
           ============================================================ --}}
      @if ($view === 'full_year')
        @php
            $months    = $data['months'];
            $lines     = $data['lines'];
            $thruMonth = (int) $data['thru_month'];
            $ccy       = $data['currency'] ?? 'XAF';
        @endphp

        <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">

            <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5]">
                <div class="text-[10.5px] text-[#7c7466] flex items-center gap-3 flex-wrap">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block w-3 h-3 rounded-sm bg-[#e9f0eb] border border-[#cbdbd0]"></span>
                        <span class="font-semibold {{ $actualText }}">Actual</span>
                        <span class="text-[#a09d95]">— closed months from GL</span>
                    </span>
                    <span class="text-[#cbc7be]">·</span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block w-3 h-3 rounded-sm bg-[#f4e2e3] border border-[#e0c5c7]"></span>
                        <span class="font-semibold {{ $budgetText }}">Budget</span>
                        <span class="text-[#a09d95]">— forecast (SMLY w/ trailing-3 fallback)</span>
                    </span>
                </div>
                <div class="text-[10.5px] text-[#7c7466]">
                    Click ▸ to expand a line · figures in <strong>million {{ $ccy }}</strong>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-[11.5px]" style="min-width: 1100px;">
                    <thead class="bg-white">
                        <tr class="border-b border-[#e8e4de]">
                            <th class="text-left py-1.5 pl-4 pr-2 sticky left-0 bg-white z-10 w-[18rem]">
                                <span class="text-[9.5px] uppercase tracking-wider text-[#8a8578] font-medium">P&amp;L Line</span>
                            </th>
                            @foreach ($months as $m)
                                @php $isActual = !$m['is_forecast']; @endphp
                                <th class="py-1.5 px-2 text-center">
                                    <span class="inline-flex text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 border font-semibold {{ $isActual ? $actualBadge : $budgetBadge }}">
                                        {{ $isActual ? 'Actual' : 'Budget' }}
                                    </span>
                                </th>
                            @endforeach
                            <th class="py-1.5 px-2 text-center">
                                <span class="inline-flex text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 border font-semibold bg-slate-100 text-slate-700 border-slate-200">Year</span>
                            </th>
                        </tr>
                        <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10 w-[18rem]">
                                <span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">Line · {{ $ccy }} (millions)</span>
                            </th>
                            @foreach ($months as $m)
                                @php $isActual = !$m['is_forecast']; @endphp
                                <th class="py-2 px-2 text-center text-[11px] tabular-nums {{ $isActual ? $actualText : $budgetText }} font-bold">
                                    {{ $m['label'] }}
                                </th>
                            @endforeach
                            <th class="py-2 px-2 text-center text-[11px] font-bold text-[#1e3d44]">{{ $year }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($lines as $line)
                            @php
                                $style       = $kindStyle[$line['kind']];
                                $isExpandable = $line['kind'] === 'leaf' && !empty($line['accounts']);
                                $isMarginRow = $line['kind'] === 'margin';
                                $isSubtotal  = $line['kind'] === 'subtotal';
                            @endphp
                            <tr class="{{ $style['bg'] }} hover:bg-[#faf8f5] transition-colors">
                                <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 {{ $style['bg'] }} z-10 w-[18rem]">
                                    @if ($isExpandable)
                                        <button type="button" class="pl-toggle inline-flex items-center gap-1.5 text-left text-[#1e3d44] hover:text-brand-700 {{ $style['font'] }} w-full" data-key="{{ $line['key'] }}">
                                            <svg class="w-3 h-3 chevron transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M9 6l6 6-6 6"/></svg>
                                            <span>{{ $line['label'] }}</span>
                                            <span class="text-[9.5px] text-[#a09d95] font-normal">({{ count($line['accounts']) }})</span>
                                        </button>
                                    @else
                                        <span class="{{ $style['font'] }} text-[#1e3d44] {{ $isMarginRow ? 'pl-[1rem]' : ($isSubtotal ? 'pl-[0.25rem]' : '') }}">{{ $line['label'] }}</span>
                                    @endif
                                </td>
                                @foreach ($months as $m)
                                    @php
                                        $isActual = !$m['is_forecast'];
                                        $colour   = $isActual ? $actualText : $budgetText;
                                        $val      = $line['monthly'][$m['num']] ?? null;
                                    @endphp
                                    <td class="text-right tabular-nums px-2 py-1.5 {{ $colour }} {{ $style['font'] }}">
                                        {{ $isMarginRow ? $fmtPct($val) : $fmtM($val) }}
                                    </td>
                                @endforeach
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $style['font'] }} text-[#1e3d44] border-l border-[#e8e4de]">
                                    @if ($isMarginRow)
                                        @php
                                            $vals = array_filter($line['monthly'], fn ($v) => $v !== null);
                                            $avg  = empty($vals) ? null : array_sum($vals) / count($vals);
                                        @endphp
                                        {{ $fmtPct($avg) }}
                                    @else
                                        {{ $fmtM($line['total']) }}
                                    @endif
                                </td>
                            </tr>
                            @if ($isExpandable)
                                @foreach ($line['accounts'] as $acct)
                                    <tr class="hidden pl-account-row bg-[#fdfcfa]" data-parent="{{ $line['key'] }}">
                                        <td class="text-left pl-12 pr-2 py-1 sticky left-0 bg-[#fdfcfa] z-10 w-[18rem] text-[10.5px] text-[#5a6a70]">
                                            <span class="font-mono text-[10px] text-[#a09d95] mr-1.5">{{ $acct['code'] }}</span>{{ $acct['name'] }}
                                        </td>
                                        @foreach ($months as $m)
                                            @php
                                                $isActual = !$m['is_forecast'];
                                                $colour   = $isActual ? $actualText : $budgetText;
                                                $val      = $acct['monthly'][$m['num']] ?? null;
                                            @endphp
                                            <td class="text-right tabular-nums px-2 py-1 {{ $colour }} text-[10.5px] opacity-80">{{ $fmtM($val) }}</td>
                                        @endforeach
                                        <td class="text-right tabular-nums px-2 py-1 text-[#5a6a70] text-[10.5px] border-l border-[#e8e4de]">{{ $fmtM($acct['total']) }}</td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-2.5 border-t border-[#e8e4de] bg-[#faf8f5] text-[10.5px] text-[#7c7466]">
                {{ $data['is_service'] ? 'Service business — direct costs basis: ' : 'Goods business — direct costs basis: ' }}
                <code class="text-[10px] bg-white border border-[#ece7df] rounded px-1 py-0.5">{{ $data['cogs_basis'] }}</code> ·
                Forecast = SMLY (Same Month Last Year) with trailing-3-month fallback.
            </div>
        </div>

      {{-- =============================================================
           MTD / YTD VIEW — Comparison columns (PY · CY · Fcst · Bdg + variances)
           ============================================================ --}}
      @else
        @php
            $lines    = $data['lines'];
            $isMtd    = $view === 'mtd';
            $periodLabel = $isMtd
                ? ($data['month_label'] ?? '') . ' ' . $data['year']
                : 'YTD ' . $data['year'];
        @endphp

        <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">

            <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5]">
                <div class="text-[10.5px] text-[#7c7466]">
                    Comparison view — <strong class="text-[#1e3d44]">{{ $periodLabel }}</strong>.
                    {{ $isMtd
                        ? 'PY = same month last year · CY = current month actual · Forecast = full-month projection · Budget = PY × growth.'
                        : 'PY = same YTD window last year · CY = current YTD actual · Forecast = full-year projection · Budget = PY year × growth.' }}
                </div>
                <div class="text-[10.5px] text-[#7c7466]">
                    Click ▸ to expand · figures in <strong>million XAF</strong>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-[11.5px]" style="min-width: 980px;">
                    <thead class="bg-white">
                        <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10 w-[18rem]">
                                <span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">P&amp;L Line · XAF (millions)</span>
                            </th>
                            <th class="py-2 px-2 text-right text-[11px] font-bold text-[#5a6a70]">Prior Year</th>
                            <th class="py-2 px-2 text-right text-[11px] font-bold {{ $actualText }}">CY P&amp;L</th>
                            <th class="py-2 px-2 text-right text-[11px] font-bold {{ $budgetText }}">Forecast</th>
                            <th class="py-2 px-2 text-right text-[11px] font-bold text-[#5a6a70]">Budget</th>
                            <th class="py-2 px-2 text-right text-[11px] font-semibold text-[#5a6a70] border-l border-[#e8e4de]">CY − PY</th>
                            <th class="py-2 px-2 text-right text-[11px] font-semibold text-[#5a6a70]">CY/PY %</th>
                            <th class="py-2 px-2 text-right text-[11px] font-semibold text-[#5a6a70] border-l border-[#e8e4de]">Fcst − Bdg</th>
                            <th class="py-2 px-2 text-right text-[11px] font-semibold text-[#5a6a70]">Fcst/Bdg %</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($lines as $line)
                            @php
                                $style        = $kindStyle[$line['kind']];
                                $isExpandable = $line['kind'] === 'leaf' && !empty($line['accounts']);
                                $isMarginRow  = $line['kind'] === 'margin';
                                $isSubtotal   = $line['kind'] === 'subtotal';
                                $v            = $line['values'];
                                $cyVsPy       = $line['variances']['cy_vs_py']    ?? ['abs' => null, 'pct' => null];
                                $fcstVsBdg    = $line['variances']['fcst_vs_bdg'] ?? ['abs' => null, 'pct' => null];
                                $betterHigh   = !in_array($line['key'], ['direct_costs', 'opex', 'depreciation', 'income_tax'], true);
                                $varCyPyAbs   = $varColour($cyVsPy['abs'],    $betterHigh);
                                $varCyPyPct   = $varColour($cyVsPy['pct'],    $betterHigh);
                                $varFbAbs     = $varColour($fcstVsBdg['abs'], $betterHigh);
                                $varFbPct     = $varColour($fcstVsBdg['pct'], $betterHigh);
                            @endphp
                            <tr class="{{ $style['bg'] }} hover:bg-[#faf8f5] transition-colors">
                                <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 {{ $style['bg'] }} z-10 w-[18rem]">
                                    @if ($isExpandable)
                                        <button type="button" class="pl-toggle inline-flex items-center gap-1.5 text-left text-[#1e3d44] hover:text-brand-700 {{ $style['font'] }} w-full" data-key="{{ $line['key'] }}">
                                            <svg class="w-3 h-3 chevron transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M9 6l6 6-6 6"/></svg>
                                            <span>{{ $line['label'] }}</span>
                                            <span class="text-[9.5px] text-[#a09d95] font-normal">({{ count($line['accounts']) }})</span>
                                        </button>
                                    @else
                                        <span class="{{ $style['font'] }} text-[#1e3d44] {{ $isMarginRow ? 'pl-[1rem]' : ($isSubtotal ? 'pl-[0.25rem]' : '') }}">{{ $line['label'] }}</span>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $style['font'] }} text-[#5a6a70]">
                                    {{ $isMarginRow ? $fmtPct($v['py']       ?? null) : $fmtM($v['py']       ?? null) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $style['font'] }} {{ $actualText }}">
                                    {{ $isMarginRow ? $fmtPct($v['cy']       ?? null) : $fmtM($v['cy']       ?? null) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $style['font'] }} {{ $budgetText }}">
                                    {{ $isMarginRow ? $fmtPct($v['forecast'] ?? null) : $fmtM($v['forecast'] ?? null) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $style['font'] }} text-[#5a6a70]">
                                    {{ $isMarginRow ? $fmtPct($v['budget']   ?? null) : $fmtM($v['budget']   ?? null) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $varCyPyAbs }} {{ $style['font'] }} border-l border-[#e8e4de]">
                                    {{ $isMarginRow ? '—' : $fmtMVar($cyVsPy['abs']) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $varCyPyPct }} {{ $style['font'] }}">
                                    {{ $fmtPctVar($cyVsPy['pct']) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $varFbAbs }} {{ $style['font'] }} border-l border-[#e8e4de]">
                                    {{ $isMarginRow ? '—' : $fmtMVar($fcstVsBdg['abs']) }}
                                </td>
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $varFbPct }} {{ $style['font'] }}">
                                    {{ $fmtPctVar($fcstVsBdg['pct']) }}
                                </td>
                            </tr>
                            @if ($isExpandable)
                                @foreach ($line['accounts'] as $acct)
                                    @php $aCyVsPy = $acct['cy_vs_py'] ?? ['abs' => null, 'pct' => null]; @endphp
                                    <tr class="hidden pl-account-row bg-[#fdfcfa]" data-parent="{{ $line['key'] }}">
                                        <td class="text-left pl-12 pr-2 py-1 sticky left-0 bg-[#fdfcfa] z-10 w-[18rem] text-[10.5px] text-[#5a6a70]">
                                            <span class="font-mono text-[10px] text-[#a09d95] mr-1.5">{{ $acct['code'] }}</span>{{ $acct['name'] }}
                                        </td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] text-[#5a6a70]">{{ $fmtM($acct['py']) }}</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] {{ $actualText }} opacity-80">{{ $fmtM($acct['cy']) }}</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] {{ $budgetText }} opacity-80">{{ $fmtM($acct['forecast']) }}</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] text-[#5a6a70]">{{ $fmtM($acct['budget']) }}</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] text-[#7c7466] border-l border-[#e8e4de]">{{ $fmtMVar($aCyVsPy['abs']) }}</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] text-[#7c7466]">{{ $fmtPctVar($aCyVsPy['pct']) }}</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] text-[#7c7466] border-l border-[#e8e4de]">—</td>
                                        <td class="text-right tabular-nums px-2 py-1 text-[10.5px] text-[#7c7466]">—</td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-2.5 border-t border-[#e8e4de] bg-[#faf8f5] text-[10.5px] text-[#7c7466]">
                {{ $data['is_service'] ? 'Service business — direct costs basis: ' : 'Goods business — direct costs basis: ' }}
                <code class="text-[10px] bg-white border border-[#ece7df] rounded px-1 py-0.5">{{ $data['cogs_basis'] }}</code> ·
                @if (!empty($data['budget_uploaded']))
                    Budget = <strong class="text-sage-700">user-uploaded</strong> (manage at <a href="{{ tenant_url('/budget-upload?year=' . $data['year']) }}" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">/budget-upload</a>).
                @else
                    Budget = PY × {{ number_format(($data['budget_growth'] ?? 1.0), 2) }} (no upload yet — <a href="{{ tenant_url('/budget-upload?year=' . $data['year']) }}" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">upload one</a> for explicit figures).
                @endif
            </div>
        </div>

        {{-- ── Commentary panel ─────────────────────────────────────────
             Free-text notes saved per (tenant, year, view, month).
             Loads back automatically when the user navigates to the
             same selection later. --}}
        @php
            $commentText = $commentary['text']         ?? '';
            $savedBy     = $commentary['saved_by']     ?? null;
            $savedAtIso  = $commentary['saved_at_iso'] ?? null;
            $monthSentinel = $isMtd ? $month : 0;
            $periodLbl = $isMtd ? ($data['month_label'] ?? '') . ' ' . $data['year'] : 'YTD ' . $data['year'];
        @endphp
        <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mt-4">
            <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                <div>
                    <h3 class="text-sm font-semibold text-[#1e3d44]">Commentary — {{ $periodLbl }}</h3>
                    <p class="text-[10.5px] text-[#7c7466] mt-0.5">Free-text notes attached to this {{ $isMtd ? 'month' : 'YTD period' }}. Saved per period — change month/view to see other commentaries.</p>
                </div>
                @if ($savedAtIso)
                    <span class="text-[10.5px] text-[#7c7466]">
                        Last saved by <strong class="text-[#1e3d44]">{{ $savedBy }}</strong>
                        · {{ \Carbon\Carbon::parse($savedAtIso)->diffForHumans() }}
                    </span>
                @endif
            </div>
            <form method="POST" action="{{ tenant_url('/pl-forecast/commentary') }}">
                @csrf
                <input type="hidden" name="year"  value="{{ $data['year'] }}">
                <input type="hidden" name="view"  value="{{ $view }}">
                <input type="hidden" name="month" value="{{ $monthSentinel }}">
                <textarea name="commentary" rows="6" maxlength="8000"
                          placeholder="What's driving the variance? Add context for next month's review …"
                          class="block w-full text-[12px] text-[#1e3d44] border border-[#e8e4de] rounded-lg p-3 bg-white focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none resize-y leading-relaxed">{{ $commentText }}</textarea>
                <div class="flex items-center justify-between mt-2">
                    <p class="text-[10.5px] text-[#7c7466]">Up to 8,000 characters. Plain text — no Markdown.</p>
                    <button type="submit" class="text-xs font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white">
                        Save commentary
                    </button>
                </div>
            </form>
        </div>
      @endif

      <script>
      (function () {
          document.querySelectorAll('.pl-toggle').forEach(function (btn) {
              btn.addEventListener('click', function () {
                  var key = btn.dataset.key;
                  if (!key) return;
                  var rows = document.querySelectorAll('tr.pl-account-row[data-parent="' + key + '"]');
                  var willOpen = rows.length > 0 && rows[0].classList.contains('hidden');
                  rows.forEach(function (r) {
                      if (willOpen) r.classList.remove('hidden'); else r.classList.add('hidden');
                  });
                  var chev = btn.querySelector('.chevron');
                  if (chev) chev.style.transform = willOpen ? 'rotate(90deg)' : '';
              });
          });
      })();
      </script>
    @endif
@endsection
