@props(['period' => []])

@php
    $type  = $period['type']  ?? 'ytd';
    $year  = $period['year']  ?? (int) date('Y');
    $month = $period['month'] ?? (int) date('m');
@endphp

<form method="GET" class="flex flex-wrap items-center gap-2 mb-6 bg-white border border-slate-200 rounded-xl p-2 shadow-sm w-fit">
    <div class="flex rounded-lg bg-slate-100 p-1 text-xs">
        @foreach ([['ytd','YTD'],['mtd','MTD'],['month','Month'],['yoy','YoY']] as [$val,$lbl])
          <label class="cursor-pointer px-3 py-1.5 rounded-md transition
                 {{ $type === $val ? 'bg-white shadow text-indigo-600 font-medium' : 'text-slate-600 hover:text-slate-900' }}">
              <input type="radio" name="period" value="{{ $val }}" {{ $type === $val ? 'checked' : '' }} class="sr-only" onchange="this.form.submit()">
              {{ $lbl }}
          </label>
        @endforeach
    </div>

    @if ($type === 'month')
      <select name="year" onchange="this.form.submit()" class="text-xs bg-white border border-slate-200 rounded px-2 py-1.5">
        @for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--)
          <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
        @endfor
      </select>
      <select name="month" onchange="this.form.submit()" class="text-xs bg-white border border-slate-200 rounded px-2 py-1.5">
        @foreach (range(1,12) as $m)
          <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>{{ date('M', mktime(0,0,0,$m,1)) }}</option>
        @endforeach
      </select>
    @endif
</form>
