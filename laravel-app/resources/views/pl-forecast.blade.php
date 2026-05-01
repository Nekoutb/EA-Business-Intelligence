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
                Full-year monthly P&amp;L. Closed months show <strong class="text-[#5d8067]">Actual</strong> figures from the GL; current and future months show <strong class="text-[#6B2D2F]">Budget</strong> (forecast). A month flips from Budget to Actual on the 1st of the following calendar month. Figures in million XAF.
            </div>
        </div>

        {{-- Year selector — past 3 years + current + 1 future --}}
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
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not fetch P&amp;L:</strong> {{ $error }}
        </div>
    @endif

    @if (!$data || empty($data['lines']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No P&amp;L lines available — check that the GL is reachable and that posted moves exist for {{ $year }}.</p>
        </div>
    @else

      @php
          $months    = $data['months'];
          $lines     = $data['lines'];
          $thruMonth = (int) $data['thru_month'];
          $ccy       = $data['currency'] ?? 'XAF';

          // Number formatter — millions XAF, no decimals, negatives in parens
          $fmtM = function ($v) {
              if ($v === null) return '—';
              if (!is_finite((float) $v)) return '—';
              $m = ((float) $v) / 1_000_000.0;
              if (abs($m) < 0.5) return '0';
              return $m < 0
                  ? '(' . number_format(-$m, 0) . ')'
                  : number_format($m, 0);
          };
          $fmtPct = function ($v) {
              if ($v === null) return '—';
              return number_format((float) $v, 1) . '%';
          };

          // Colours per Actual / Budget split (green vs oxblood) — per
          // explicit user spec.
          $actualText  = 'text-[#5d8067]'; // green/sage tone
          $budgetText  = 'text-[#6B2D2F]'; // oxblood
          $actualBadge = 'bg-[#e9f0eb] text-[#3d5e48] border-[#cbdbd0]';
          $budgetBadge = 'bg-[#f4e2e3] text-[#6B2D2F] border-[#e0c5c7]';

          // Map kind → row styling. The table renders these as data-rows,
          // each with a colour bar based on the line.
          $kindStyle = [
              'leaf'     => ['bg' => 'bg-white',     'font' => 'font-medium',    'border' => 'border-[#f1ede5]'],
              'subtotal' => ['bg' => 'bg-[#fcfbf8]', 'font' => 'font-bold',      'border' => 'border-[#e8e4de]'],
              'margin'   => ['bg' => 'bg-[#fcfbf8]', 'font' => 'font-semibold italic', 'border' => 'border-[#f1ede5]'],
          ];
      @endphp

      <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">

          {{-- Top legend strip — clarifies the colour key --}}
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
                  Click ▸ to expand a line to its accounts · figures in <strong>million {{ $ccy }}</strong>
              </div>
          </div>

          <div class="overflow-x-auto">
              <table class="w-full text-[11.5px]" style="min-width: 1100px;">
                  {{-- ── Column header: status label row + month name row ── --}}
                  <thead class="bg-white">
                      {{-- Row 1: ACTUAL / BUDGET status badges --}}
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
                      {{-- Row 2: Month names --}}
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

                  {{-- ── Body: P&L lines ─────────────────────────────────── --}}
                  <tbody class="divide-y divide-[#f1ede5]">
                      @foreach ($lines as $line)
                          @php
                              $style       = $kindStyle[$line['kind']];
                              $isExpandable = $line['kind'] === 'leaf' && !empty($line['accounts']);
                              $isMarginRow = $line['kind'] === 'margin';
                              $isSubtotal  = $line['kind'] === 'subtotal';
                          @endphp

                          {{-- Parent / aggregate row --}}
                          <tr class="{{ $style['bg'] }} hover:bg-[#faf8f5] transition-colors">
                              <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 {{ $style['bg'] }} z-10 w-[18rem]">
                                  @if ($isExpandable)
                                      <button type="button"
                                              class="pl-toggle inline-flex items-center gap-1.5 text-left text-[#1e3d44] hover:text-brand-700 {{ $style['font'] }} w-full"
                                              data-key="{{ $line['key'] }}">
                                          <svg class="w-3 h-3 chevron transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                              <path stroke-linecap="round" d="M9 6l6 6-6 6"/>
                                          </svg>
                                          <span>{{ $line['label'] }}</span>
                                          <span class="text-[9.5px] text-[#a09d95] font-normal">({{ count($line['accounts']) }})</span>
                                      </button>
                                  @else
                                      <span class="{{ $style['font'] }} text-[#1e3d44] {{ $isMarginRow ? 'pl-[1rem]' : ($isSubtotal ? 'pl-[0.25rem]' : '') }}">
                                          {{ $line['label'] }}
                                      </span>
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
                                          // Year margin = Net (or relevant) / Revenue across the full year
                                          $yearVals = array_values($line['monthly']);
                                          $yearAvg  = array_sum(array_filter($yearVals, fn($v) => $v !== null)) /
                                                      max(1, count(array_filter($yearVals, fn($v) => $v !== null)));
                                      @endphp
                                      {{ $fmtPct($yearAvg) }}
                                  @else
                                      {{ $fmtM($line['total']) }}
                                  @endif
                              </td>
                          </tr>

                          {{-- Drill-down rows: per-account, hidden by default --}}
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
                                          <td class="text-right tabular-nums px-2 py-1 {{ $colour }} text-[10.5px] opacity-80">
                                              {{ $fmtM($val) }}
                                          </td>
                                      @endforeach
                                      <td class="text-right tabular-nums px-2 py-1 text-[#5a6a70] text-[10.5px] border-l border-[#e8e4de]">
                                          {{ $fmtM($acct['total']) }}
                                      </td>
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
              Forecast = Same Month Last Year (SMLY); falls back to trailing-3-month mean of current-year actuals when prior year is empty.
          </div>
      </div>

      <script>
      (function () {
          // Toggle drill-down rows when a chevron is clicked.
          document.querySelectorAll('.pl-toggle').forEach(function (btn) {
              btn.addEventListener('click', function () {
                  var key = btn.dataset.key;
                  if (!key) return;
                  var rows = document.querySelectorAll('tr.pl-account-row[data-parent="' + key + '"]');
                  var willOpen = rows.length > 0 && rows[0].classList.contains('hidden');
                  rows.forEach(function (r) {
                      if (willOpen) r.classList.remove('hidden'); else r.classList.add('hidden');
                  });
                  // Rotate the chevron 90° on expand
                  var chev = btn.querySelector('.chevron');
                  if (chev) chev.style.transform = willOpen ? 'rotate(90deg)' : '';
              });
          });
      })();
      </script>
    @endif
@endsection
