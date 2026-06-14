@extends('layouts.app')
@section('title', 'P&L Forecast')

@section('page-title', 'P&L Forecast')
@section('content')
    {{-- Quick-jump to Budget Upload — the forecast leans on the budget
         feed, so we surface the management page right at the top. The
         page also has its own sidebar nav entry, but a CTA here keeps
         users in flow when they realise the forecast is missing budget
         input. --}}
    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm p-4 mb-4 flex items-center justify-between gap-3 flex-wrap">
        <div>
            <div class="text-[13px] font-semibold text-[#1e3d44]">Budget Upload</div>
            <p class="text-[11px] text-[#7c7466] mt-0.5">Upload, review, or replace the annual budget the forecast compares against. Excel template available.</p>
        </div>
        <a href="{{ tenant_url('/budget-upload') }}" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg px-3 py-2 shadow-sm transition flex-shrink-0">
            Manage budget →
        </a>
    </div>

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

            // ── Forecast Assumptions section state ──
            $A   = $data['assumptions'] ?? null;
            $def = $data['assumption_defaults'] ?? null;
            $monthNames = ['', 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $fcFrom  = min(12, $thruMonth + 1);
            $fcLabel = $thruMonth >= 12 ? 'no future months (year closed)' : ($monthNames[$fcFrom] . '–Dec ' . $data['year']);
            $actualsLabel = $thruMonth >= 1 ? ('Jan–' . ($monthNames[$thruMonth] ?? '')) : 'no closed months';
            $mfmt = fn ($v) => $v === null ? '—' : number_format(((float) $v) / 1e6, 1) . 'M';
        @endphp

        {{-- ── Forecast Assumptions — drives the future (Forecast) months ── --}}
        <details class="bg-white border border-[#e8e4de] rounded-xl shadow-sm mb-4 group" open>
            <summary class="cursor-pointer list-none flex items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                <div class="flex items-center gap-2 flex-wrap">
                    <svg class="w-4 h-4 text-[#7c7466] transition-transform group-open:rotate-90 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M9 6l6 6-6 6"/></svg>
                    <span class="text-[13px] font-semibold text-[#1e3d44]">Forecast Assumptions</span>
                    <span class="text-[10.5px] text-[#a09d95]">drives the Forecast months ({{ $fcLabel }})</span>
                </div>
                @if ($A)
                    <span class="text-[10px] text-[#7c7466] flex-shrink-0">defaults seeded from {{ $actualsLabel }} actuals</span>
                @endif
            </summary>

            @if (!$A)
                <div class="border-t border-warm-200 bg-warm-50 px-4 py-3 text-[12px] text-warm-800">
                    Assumptions are temporarily unavailable — the forecast is using the statistical fallback (same-month-last-year). Reload once the Odoo connection settles.
                </div>
            @else
            {{-- Posts to the already-live /pl-forecast/commentary route (the dedicated
                 /assumptions route 404s behind a stale route cache on this host); the
                 controller delegates on the _intent marker below. --}}
            <form method="POST" action="{{ tenant_url('/pl-forecast/commentary') }}" class="border-t border-[#f1ede5] px-4 py-4">
                @csrf
                <input type="hidden" name="_intent" value="assumptions">
                <input type="hidden" name="year" value="{{ $data['year'] }}">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 text-[12px]">

                    {{-- Revenue --}}
                    <div class="border border-[#e8e4de] rounded-lg p-3">
                        <div class="font-semibold text-[#1e3d44] mb-2">Revenue</div>
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">Method</label>
                        <select name="revenue_method" class="w-full border border-[#d9d4ca] rounded px-2 py-1 text-[12px] mb-2 bg-white">
                            <option value="annual_target" {{ $A['revenue']['method']==='annual_target'?'selected':'' }}>Target · split evenly across forecast months</option>
                            <option value="growth_pct"    {{ $A['revenue']['method']==='growth_pct'?'selected':'' }}>YoY growth % vs last year</option>
                            <option value="run_rate"      {{ $A['revenue']['method']==='run_rate'?'selected':'' }}>Flat monthly run-rate</option>
                        </select>
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">Revenue target for forecast period (XAF)</label>
                        <input type="number" step="1000000" name="revenue_annual_target" value="{{ (int) $A['revenue']['annual_target'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 mb-0.5 tabular-nums">
                        <div class="text-[10px] text-[#a09d95] mb-2">≈ {{ $mfmt($A['revenue']['annual_target']) }} total · with the "split evenly" method, divided equally across the {{ $fcLabel }} months</div>
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">YoY growth % <span class="text-[#bcb6ab]">(growth method)</span></label>
                        <input type="number" step="0.1" name="revenue_growth_pct" value="{{ $A['revenue']['growth_pct'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 tabular-nums">
                    </div>

                    {{-- Margin & OpEx --}}
                    <div class="border border-[#e8e4de] rounded-lg p-3">
                        <div class="font-semibold text-[#1e3d44] mb-2">Margin &amp; operating expenses</div>
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">Gross margin %</label>
                        <input type="number" step="0.1" name="gross_margin_pct" value="{{ $A['gross_margin_pct'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 mb-0.5 tabular-nums">
                        <div class="text-[10px] text-[#a09d95] mb-2">default {{ number_format((float) ($def['gross_margin_pct'] ?? 0), 1) }}% (trailing actual)</div>
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">OpEx method</label>
                        <select name="opex_method" class="w-full border border-[#d9d4ca] rounded px-2 py-1 text-[12px] mb-2 bg-white">
                            <option value="fixed"          {{ $A['opex']['method']==='fixed'?'selected':'' }}>Fixed XAF / month (+ inflation)</option>
                            <option value="pct_of_revenue" {{ $A['opex']['method']==='pct_of_revenue'?'selected':'' }}>% of revenue</option>
                        </select>
                        <div class="grid grid-cols-3 gap-2">
                            <div><label class="block text-[10.5px] text-[#7c7466] mb-1">XAF/mo</label>
                                <input type="number" step="100000" name="opex_monthly" value="{{ (int) $A['opex']['monthly'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 tabular-nums"></div>
                            <div><label class="block text-[10.5px] text-[#7c7466] mb-1">Infl %</label>
                                <input type="number" step="0.1" name="opex_inflation_pct" value="{{ $A['opex']['inflation_pct'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 tabular-nums"></div>
                            <div><label class="block text-[10.5px] text-[#7c7466] mb-1">% of rev</label>
                                <input type="number" step="0.1" name="opex_pct_of_revenue" value="{{ $A['opex']['pct_of_revenue'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 tabular-nums"></div>
                        </div>
                        <div class="text-[10px] text-[#a09d95] mt-1">default {{ $mfmt($def['opex']['monthly'] ?? null) }}/mo</div>
                    </div>

                    {{-- D&A · Financial · Tax --}}
                    <div class="border border-[#e8e4de] rounded-lg p-3">
                        <div class="font-semibold text-[#1e3d44] mb-2">D&amp;A · Financial · Tax</div>
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">Depreciation &amp; amortisation / month (XAF)</label>
                        <input type="number" step="100000" name="da_monthly" value="{{ (int) $A['da_monthly'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 mb-2 tabular-nums">
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">Net financial result / month (XAF · − if a cost)</label>
                        <input type="number" step="100000" name="net_financial_monthly" value="{{ (int) $A['net_financial_monthly'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 mb-2 tabular-nums">
                        <label class="block text-[10.5px] text-[#7c7466] mb-1">Income tax</label>
                        <select name="tax_method" class="w-full border border-[#d9d4ca] rounded px-2 py-1 text-[12px] mb-2 bg-white">
                            <option value="rate"  {{ $A['tax']['method']==='rate'?'selected':'' }}>Effective rate % on profit</option>
                            <option value="fixed" {{ $A['tax']['method']==='fixed'?'selected':'' }}>Fixed XAF / month</option>
                            <option value="none"  {{ $A['tax']['method']==='none'?'selected':'' }}>None</option>
                        </select>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[10.5px] text-[#7c7466] mb-1">Rate %</label>
                                <input type="number" step="0.5" name="tax_rate_pct" value="{{ $A['tax']['rate_pct'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 tabular-nums"></div>
                            <div><label class="block text-[10.5px] text-[#7c7466] mb-1">Or fixed/mo</label>
                                <input type="number" step="100000" name="tax_monthly" value="{{ (int) $A['tax']['monthly'] }}" class="w-full border border-[#d9d4ca] rounded px-2 py-1 tabular-nums"></div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 mt-3 flex-wrap">
                    <p class="text-[10.5px] text-[#7c7466] max-w-2xl leading-relaxed">
                        Saving recomputes the <strong>Forecast</strong> months ({{ $fcLabel }}) from these drivers and cascades them down the statement.
                        Closed months stay as <strong>Actuals</strong> and roll forward automatically as each month ends.
                    </p>
                    <button type="submit" class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg px-4 py-2 shadow-sm flex-shrink-0">
                        Save assumptions &amp; re-forecast
                    </button>
                </div>
            </form>
            @endif
        </details>

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
                        <span class="font-semibold {{ $budgetText }}">Forecast</span>
                        <span class="text-[#a09d95]">— future months, driven by the assumptions below</span>
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
                                        {{ $isActual ? 'Actual' : 'Forecast' }}
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
           MTD / YTD VIEW — Comparison columns (PY · CY · Fcst · Bdg + variances + Commentary)
           ============================================================ --}}
      @else
        @php
            $lines    = $data['lines'];
            $isMtd    = $view === 'mtd';
            $periodLabel = $isMtd
                ? ($data['month_label'] ?? '') . ' ' . $data['year']
                : 'YTD ' . $data['year'];
            $monthSentinel = $isMtd ? $month : 0;
            $commentaries  = $commentaries ?? [];
            $commentaryMeta = $commentary_meta ?? null;
        @endphp

        {{-- Wrap the entire table in a single form so all per-row
             commentary textareas get saved with one click. --}}
        <form method="POST" action="{{ tenant_url('/pl-forecast/commentary') }}" id="ea-pl-commentary-form">
            @csrf
            <input type="hidden" name="year"  value="{{ $data['year'] }}">
            <input type="hidden" name="view"  value="{{ $view }}">
            <input type="hidden" name="month" value="{{ $monthSentinel }}">

            <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">

                <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5]">
                    <div class="text-[10.5px] text-[#7c7466]">
                        Comparison view — <strong class="text-[#1e3d44]">{{ $periodLabel }}</strong>.
                        {{ $isMtd
                            ? 'PY = same month last year · CY = current month actual · Forecast = full-month projection · Budget = PY × growth or uploaded.'
                            : 'PY = same YTD window last year · CY = current YTD actual · Forecast = full-year projection · Budget = full-year (PY × growth or uploaded).' }}
                    </div>
                    <div class="text-[10.5px] text-[#7c7466]">
                        @if ($commentaryMeta)
                            Commentaries last saved by <strong class="text-[#1e3d44]">{{ $commentaryMeta['by'] }}</strong>
                            · {{ \Carbon\Carbon::parse($commentaryMeta['at'])->diffForHumans() }} ·
                        @endif
                        figures in <strong>million XAF</strong>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-[11.5px]" style="min-width: 1180px;">
                        <thead class="bg-white">
                            <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                                <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10" style="width:14rem;">
                                    <span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">P&amp;L Line</span>
                                </th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-bold text-[#5a6a70]" style="width:4.5rem;">Prior Year</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-bold {{ $actualText }}" style="width:4.5rem;">CY P&amp;L</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-bold {{ $budgetText }}" style="width:4.5rem;">Forecast</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-bold text-[#5a6a70]" style="width:4.5rem;">Budget</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-semibold text-[#5a6a70] border-l border-[#e8e4de]" style="width:4.5rem;">CY − PY</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-semibold text-[#5a6a70]" style="width:4rem;">CY/PY %</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-semibold text-[#5a6a70] border-l border-[#e8e4de]" style="width:4.5rem;">Fcst − Bdg</th>
                                <th class="py-2 px-1.5 text-right text-[10.5px] font-semibold text-[#5a6a70]" style="width:4.5rem;">Fcst/Bdg %</th>
                                <th class="py-2 px-2 text-left text-[10.5px] font-semibold text-[#1e3d44] border-l-2 border-brand-200" style="width:18rem;">Commentary</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f1ede5]">
                            @foreach ($lines as $line)
                                @php
                                    $style        = $kindStyle[$line['kind']];
                                    $isExpandable = $line['kind'] === 'leaf' && !empty($line['accounts']);
                                    $isMarginRow  = $line['kind'] === 'margin';
                                    $isSubtotal   = $line['kind'] === 'subtotal';
                                    $isLeaf       = $line['kind'] === 'leaf';
                                    $v            = $line['values'];
                                    $cyVsPy       = $line['variances']['cy_vs_py']    ?? ['abs' => null, 'pct' => null];
                                    $fcstVsBdg    = $line['variances']['fcst_vs_bdg'] ?? ['abs' => null, 'pct' => null];
                                    $betterHigh   = !in_array($line['key'], ['direct_costs', 'opex', 'depreciation', 'income_tax'], true);
                                    $varCyPyAbs   = $varColour($cyVsPy['abs'],    $betterHigh);
                                    $varCyPyPct   = $varColour($cyVsPy['pct'],    $betterHigh);
                                    $varFbAbs     = $varColour($fcstVsBdg['abs'], $betterHigh);
                                    $varFbPct     = $varColour($fcstVsBdg['pct'], $betterHigh);
                                    $cellComment  = $commentaries[$line['key']] ?? '';
                                @endphp
                                <tr class="{{ $style['bg'] }} hover:bg-[#faf8f5] transition-colors">
                                    <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 {{ $style['bg'] }} z-10" style="width:14rem;">
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
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $style['font'] }} text-[#5a6a70]">{{ $isMarginRow ? $fmtPct($v['py']       ?? null) : $fmtM($v['py']       ?? null) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $style['font'] }} {{ $actualText }}">{{ $isMarginRow ? $fmtPct($v['cy']       ?? null) : $fmtM($v['cy']       ?? null) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $style['font'] }} {{ $budgetText }}">{{ $isMarginRow ? $fmtPct($v['forecast'] ?? null) : $fmtM($v['forecast'] ?? null) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $style['font'] }} text-[#5a6a70]">{{ $isMarginRow ? $fmtPct($v['budget']   ?? null) : $fmtM($v['budget']   ?? null) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $varCyPyAbs }} {{ $style['font'] }} border-l border-[#e8e4de]">{{ $isMarginRow ? '—' : $fmtMVar($cyVsPy['abs']) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $varCyPyPct }} {{ $style['font'] }}">{{ $fmtPctVar($cyVsPy['pct']) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $varFbAbs }} {{ $style['font'] }} border-l border-[#e8e4de]">{{ $isMarginRow ? '—' : $fmtMVar($fcstVsBdg['abs']) }}</td>
                                    <td class="text-right tabular-nums px-1.5 py-1.5 {{ $varFbPct }} {{ $style['font'] }}">{{ $fmtPctVar($fcstVsBdg['pct']) }}</td>
                                    <td class="px-2 py-1 align-middle border-l-2 border-brand-100">
                                        @if ($isLeaf)
                                            <textarea name="commentaries[{{ $line['key'] }}]" rows="2" maxlength="8000"
                                                      placeholder="Explain variance…"
                                                      class="block w-full text-[11px] leading-snug text-[#1e3d44] border border-[#e8e4de] rounded p-1.5 bg-white focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none resize-y">{{ $cellComment }}</textarea>
                                        @else
                                            <span class="text-[10px] text-[#a09d95] italic">—</span>
                                        @endif
                                    </td>
                                </tr>
                                @if ($isExpandable)
                                    @foreach ($line['accounts'] as $acct)
                                        @php $aCyVsPy = $acct['cy_vs_py'] ?? ['abs' => null, 'pct' => null]; @endphp
                                        <tr class="hidden pl-account-row bg-[#fdfcfa]" data-parent="{{ $line['key'] }}">
                                            <td class="text-left pl-12 pr-2 py-1 sticky left-0 bg-[#fdfcfa] z-10 text-[10.5px] text-[#5a6a70]" style="width:14rem;">
                                                <span class="font-mono text-[10px] text-[#a09d95] mr-1.5">{{ $acct['code'] }}</span>{{ $acct['name'] }}
                                            </td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] text-[#5a6a70]">{{ $fmtM($acct['py']) }}</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] {{ $actualText }} opacity-80">{{ $fmtM($acct['cy']) }}</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] {{ $budgetText }} opacity-80">{{ $fmtM($acct['forecast']) }}</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] text-[#5a6a70]">{{ $fmtM($acct['budget']) }}</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] text-[#7c7466] border-l border-[#e8e4de]">{{ $fmtMVar($aCyVsPy['abs']) }}</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] text-[#7c7466]">{{ $fmtPctVar($aCyVsPy['pct']) }}</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] text-[#7c7466] border-l border-[#e8e4de]">—</td>
                                            <td class="text-right tabular-nums px-1.5 py-1 text-[10.5px] text-[#7c7466]">—</td>
                                            <td class="px-2 py-1 border-l-2 border-brand-100"></td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between gap-3 px-4 py-3 border-t border-[#e8e4de] bg-[#faf8f5] flex-wrap">
                    <div class="text-[10.5px] text-[#7c7466]">
                        {{ $data['is_service'] ? 'Service business — direct costs basis: ' : 'Goods business — direct costs basis: ' }}
                        <code class="text-[10px] bg-white border border-[#ece7df] rounded px-1 py-0.5">{{ $data['cogs_basis'] }}</code> ·
                        @if (!empty($data['budget_uploaded']))
                            Budget = <strong class="text-sage-700">user-uploaded</strong>
                            (<a href="{{ tenant_url('/budget-upload?year=' . $data['year']) }}" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">manage</a>)
                        @else
                            Budget = PY × {{ number_format(($data['budget_growth'] ?? 1.0), 2) }}
                            (<a href="{{ tenant_url('/budget-upload?year=' . $data['year']) }}" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">upload</a> a budget)
                        @endif
                    </div>
                    <button type="submit" class="text-xs font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white inline-flex items-center gap-1.5">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Save commentaries
                    </button>
                </div>
            </div>
        </form>
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
