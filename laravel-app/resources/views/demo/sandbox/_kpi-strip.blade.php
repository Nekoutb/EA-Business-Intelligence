{{--
  Shared KPI strip for every demo module page.

  Usage:
    @include('demo.sandbox._kpi-strip', ['kpis' => [
        ['label' => 'Revenue YoY', 'value_fmt' => '+8.2%', 'status' => 'green',
         'subtitle' => 'prev 21.4M', 'threshold' => 'green > +5%, amber -5..+5, red < -5%'],
        ...
    ]])

  Each KPI requires: label, value_fmt, status (green|amber|red|na).
  Optional: subtitle (small text under value), threshold (rule shown
  at the bottom of the card, after a thin divider).

  Renders a responsive grid that adapts column count to the number of
  KPIs (1-5+). Matches the Dashboard's traffic-light style.

  Hardening notes:
    - `$kpis ?? []` so an un-passed variable doesn't error (Laravel's
      strict-variables flag would otherwise throw).
    - `@if (!empty($kpis))` guard around the whole block — `return;`
      inside a raw-PHP block inside an included view does NOT cleanly
      exit the parent view's render function under some Blade compiles,
      so we wrap with @if instead. (Do not write the raw-PHP directive's
      literal token in comments — Blade's block extractor reads tokens
      even inside comments and mis-pairs them; that corrupted this
      compiled view.)
    - Every $kpi['*'] access uses `?? ''` / `?? 'na'` so a malformed
      builder row can't take down the whole strip.
--}}
@php
    $kpis = (isset($kpis) && is_array($kpis)) ? $kpis : [];
    $cols = min(5, max(1, count($kpis)));
    $colsClass = match($cols) {
        1 => 'md:grid-cols-1',
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-3',
        4 => 'md:grid-cols-4',
        default => 'md:grid-cols-5',
    };
@endphp
@if (!empty($kpis))
<div class="grid grid-cols-2 {{ $colsClass }} gap-3 mb-5">
    @foreach ($kpis as $kpi)
        @php
            $kpi = is_array($kpi) ? $kpi : [];
            $status = (string) ($kpi['status'] ?? 'na');
            $shade = match($status) {
                'red'   => 'bg-rose-50/60 border-rose-200',
                'amber' => 'bg-amber-50/60 border-amber-200',
                'green' => 'bg-emerald-50/60 border-emerald-200',
                default => 'bg-stone-50/60 border-stone-200',
            };
            $dot = match($status) {
                'red'   => 'bg-rose-500',
                'amber' => 'bg-amber-500',
                'green' => 'bg-emerald-500',
                default => 'bg-stone-300',
            };
            $valueColor = match($status) {
                'red'   => 'text-rose-700',
                'amber' => 'text-amber-700',
                'green' => 'text-emerald-700',
                default => 'text-slate-900',
            };
        @endphp
        <div class="rounded-xl border {{ $shade }} p-4">
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold leading-tight">{{ $kpi['label'] ?? '' }}</span>
                <span class="w-2 h-2 rounded-full {{ $dot }} shrink-0" title="{{ ucfirst($status) }}"></span>
            </div>
            <div class="text-xl font-bold {{ $valueColor }} mono">{{ $kpi['value_fmt'] ?? '—' }}</div>
            @if (!empty($kpi['subtitle']))
                <div class="text-[10px] text-slate-500 mt-0.5 mono">{{ $kpi['subtitle'] }}</div>
            @endif
            @if (!empty($kpi['threshold']))
                <div class="text-[9.5px] text-slate-500 leading-snug mt-1.5 border-t border-slate-200/60 pt-1.5">{{ $kpi['threshold'] }}</div>
            @endif
        </div>
    @endforeach
</div>
@endif
