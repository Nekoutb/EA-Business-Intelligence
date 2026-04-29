{{--
    Period filter — radio-tab control that submits as a GET form.

    Props:
      $period   — the active period array (['type' => …, 'year' => …, 'month' => …]).
      $options  — list of [value, label] pairs to render. Defaults to the
                  full 4-tab set (YTD / MTD / Month / YoY). Pages that only
                  want a subset (e.g. the Dashboard which only needs
                  YTD/MTD) pass an explicit list:
                      <x-period-filter :period="$period" :options="[['ytd','YTD'],['mtd','MTD']]" />
                  The Year/Month dropdowns only render when 'month' is in
                  the options AND is the active type.
--}}
@props([
    'period'  => [],
    'options' => [['ytd','YTD'],['mtd','MTD'],['month','Month'],['yoy','YoY']],
])

@php
    $type   = $period['type']  ?? 'ytd';
    $year   = $period['year']  ?? (int) date('Y');
    $month  = $period['month'] ?? (int) date('m');
    $hasMonthOption = collect($options)->contains(fn ($o) => ($o[0] ?? null) === 'month');
@endphp

<form method="GET" class="flex flex-wrap items-center gap-2 mb-6 bg-white border border-slate-200 rounded-xl p-2 shadow-sm w-fit">
    <div class="flex rounded-lg bg-slate-100 p-1 text-xs">
        @foreach ($options as [$val, $lbl])
          <label class="cursor-pointer px-3 py-1.5 rounded-md transition
                 {{ $type === $val ? 'bg-white shadow text-indigo-600 font-medium' : 'text-slate-600 hover:text-slate-900' }}">
              <input type="radio" name="period" value="{{ $val }}" {{ $type === $val ? 'checked' : '' }} class="sr-only" onchange="this.form.submit()">
              {{ $lbl }}
          </label>
        @endforeach
    </div>

    {{-- Year selector — shown on Month (always) and YTD (when the page
         supports the multi-period set, i.e. exposes the Month option).
         Hidden on MTD because MTD is always "current month, current year"
         by definition. --}}
    @if ($hasMonthOption && ($type === 'ytd' || $type === 'month'))
      <select name="year" onchange="this.form.submit()" class="text-xs bg-white border border-slate-200 rounded px-2 py-1.5">
        @for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--)
          <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
        @endfor
      </select>
    @endif

    {{-- Month selector — only on Month type. --}}
    @if ($hasMonthOption && $type === 'month')
      <select name="month" onchange="this.form.submit()" class="text-xs bg-white border border-slate-200 rounded px-2 py-1.5">
        @foreach (range(1,12) as $m)
          <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>{{ date('M', mktime(0,0,0,$m,1)) }}</option>
        @endforeach
      </select>
    @endif
</form>
