@extends('layouts.app')
@section('title', 'Productivity')

@section('page-title', 'Productivity')
@section('content')
@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
@elseif ($data)
    @php
        $k   = $data['kpis'];
        $m   = fn ($v) => $v === null ? '—' : ea_money_short((float) $v, 'XAF');
        $x   = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ');
        $lm  = $k['last_month'] ?? [];
        $th  = 'px-3 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td  = 'px-3 py-1.5 text-[12px] text-[#2d3748]';
        $num = 'text-right tabular-nums';
        $thisYear = (int) date('Y');
        $chart = [
            'labels'      => array_values(array_map(fn ($r) => $r['label'], $data['rows'])),
            'perEmployee' => array_values(array_map(fn ($r) => $r['per_employee'] === null ? null : round($r['per_employee']), $data['rows'])),
            'perFile'     => array_values(array_map(fn ($r) => $r['per_file'] === null ? null : round($r['per_file']), $data['rows'])),
            'depts'       => array_map(fn ($d) => ['name' => $d['department'], 'data' => array_values($d['monthly'])], $data['departments']),
        ];
    @endphp

    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <div class="text-[11px] text-[#7c7466]">{{ $year }} · tracked monthly · employee cost = accounts 66 (personnel) + 625 (medical insurance)</div>
        <div class="inline-flex items-center gap-1 bg-white border border-[#e8e4de] rounded-lg p-1 shadow-sm">
            @foreach (range($thisYear - 1, $thisYear) as $y)
                <a href="{{ tenant_url('/hr/productivity?year=' . $y) }}"
                   class="px-2.5 py-1 text-[11px] font-semibold rounded {{ (int) $year === $y ? 'bg-brand-600 text-white' : 'text-[#7c7466] hover:bg-[#f5f2ec]' }}">{{ $y }}</a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        <x-kpi title="Employee cost YTD" :value="$m($k['cost_ytd'])" :sub="'66: ' . $m($k['cost_66_ytd']) . ' · 625: ' . $m($k['cost_625_ytd'])" color="indigo" />
        <x-kpi title="Employees" :value="number_format((int) $k['employees'])" sub="profiles in the system" color="slate" />
        <x-kpi title="Files created YTD" :value="number_format((int) $k['files_ytd'])" sub="clearance files" color="slate" />
        <x-kpi title="Employee cost / file YTD" :value="$m($k['per_file_ytd'])" :sub="$m($k['cost_ytd']) . ' ÷ ' . number_format((int) $k['files_ytd']) . ' files'" color="amber" />
    </div>

    {{-- Sandbox 1 — Employee cost per employee --}}
    <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-4">
        <div class="flex items-baseline justify-between gap-3 flex-wrap">
            <h2 class="text-sm font-semibold text-[#1e3d44]">Employee cost per employee</h2>
            <div class="text-[12px] text-[#2d3748]">Latest month <b>{{ $m($lm['per_employee'] ?? null) }}</b> · monthly average {{ $m($k['per_employee_month']) }}</div>
        </div>
        <p class="text-[11px] text-[#7c7466] mb-3">Month's employee cost (66 + 625) ÷ employee profiles existing at the month end.</p>
        <div class="h-56"><canvas id="hrPerEmp"></canvas></div>
    </div>

    {{-- Sandbox 2 — Employee cost per file --}}
    <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-4">
        <div class="flex items-baseline justify-between gap-3 flex-wrap">
            <h2 class="text-sm font-semibold text-[#1e3d44]">Total employee cost per file</h2>
            <div class="text-[12px] text-[#2d3748]">Latest month <b>{{ $m($lm['per_file'] ?? null) }}</b> · year to date {{ $m($k['per_file_ytd']) }}</div>
        </div>
        <p class="text-[11px] text-[#7c7466] mb-3">Month's employee cost (66 + 625) ÷ clearance files created in the month.</p>
        <div class="h-56"><canvas id="hrPerFile"></canvas></div>
    </div>

    {{-- Sandbox 3 — Files per department --}}
    <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-4">
        <h2 class="text-sm font-semibold text-[#1e3d44]">Files per department</h2>
        <p class="text-[11px] text-[#7c7466] mb-3">Distinct files each department acted on (any recorded action on the file or its disbursements by a member). Department = the user's clearance role; a user holding roles in two departments counts for both.</p>
        <div class="h-56 mb-4"><canvas id="hrDept"></canvas></div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Department</th><th class="{{ $th }} text-left">Roles</th><th class="{{ $th }} {{ $num }}">Members</th>
                    @foreach ($data['months'] as $ym => $label)<th class="{{ $th }} {{ $num }}">{{ $label }}</th>@endforeach
                    <th class="{{ $th }} {{ $num }}">YTD</th>
                </tr></thead>
                <tbody>
                @foreach ($data['departments'] as $d)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }} font-semibold">{{ $d['department'] }}</td>
                        <td class="{{ $td }} text-[#7c7466] text-[11px]">{{ $d['roles'] ? implode(' · ', $d['roles']) : 'no role defined' }}</td>
                        <td class="{{ $td }} {{ $num }}">{{ $d['members'] }}</td>
                        @foreach ($d['monthly'] as $n)<td class="{{ $td }} {{ $num }}">{{ $n ?: '—' }}</td>@endforeach
                        <td class="{{ $td }} {{ $num }} font-semibold">{{ $d['ytd'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm mb-4 overflow-x-auto">
        <h2 class="text-sm font-semibold text-[#1e3d44] px-5 pt-4 pb-2">Monthly detail</h2>
        <table class="w-full">
            <thead class="bg-[#faf9f6]"><tr>
                <th class="{{ $th }} text-left">Month</th><th class="{{ $th }} {{ $num }}">Personnel (66)</th><th class="{{ $th }} {{ $num }}">Medical insurance (625)</th>
                <th class="{{ $th }} {{ $num }}">Employee cost</th><th class="{{ $th }} {{ $num }}">Employees</th><th class="{{ $th }} {{ $num }}">Cost / employee</th>
                <th class="{{ $th }} {{ $num }}">Files created</th><th class="{{ $th }} {{ $num }}">Cost / file</th>
            </tr></thead>
            <tbody>
            @foreach ($data['rows'] as $r)
                <tr class="border-t border-[#f1ede5]">
                    <td class="{{ $td }}">{{ $r['label'] }}</td><td class="{{ $td }} {{ $num }}">{{ $x($r['cost_66']) }}</td><td class="{{ $td }} {{ $num }}">{{ $x($r['cost_625']) }}</td>
                    <td class="{{ $td }} {{ $num }} font-semibold">{{ $x($r['cost']) }}</td><td class="{{ $td }} {{ $num }}">{{ $r['employees'] }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $x($r['per_employee']) }}</td><td class="{{ $td }} {{ $num }}">{{ $r['files'] }}</td><td class="{{ $td }} {{ $num }}">{{ $x($r['per_file']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if (!empty($data['notes']))
        <div class="bg-[#f5f2ec] border border-[#e8e4de] text-[#7c7466] rounded-lg p-3 text-[11px]"><span class="font-semibold">Notes:</span> {{ implode(' ', $data['notes']) }}</div>
    @endif

    @push('scripts')
    <script>
    (function () {
        const c = @json($chart);
        const fmt = v => { if (v == null) return '—'; v = Math.abs(+v || 0); return v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : Math.round(v); };
        const opts = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{ backgroundColor:'#1e3d44', callbacks:{ label: x => (x.dataset.label ? x.dataset.label + ': ' : '') + fmt(x.parsed.y) } } },
            scales:{ x:{ grid:{display:false}, ticks:{font:{size:10}} }, y:{ grid:{color:'#f1ede5'}, ticks:{font:{size:10}, callback:fmt} } } };
        const line = (id, data, color) => { const el = document.getElementById(id); if (window.Chart && el) new Chart(el, { type:'line',
            data:{ labels:c.labels, datasets:[{ data, borderColor:color, backgroundColor:color, tension:.3, borderWidth:2, pointRadius:3, spanGaps:false }] }, options:opts }); };
        line('hrPerEmp', c.perEmployee, '#2f6f76');
        line('hrPerFile', c.perFile, '#d98c3f');
        const pal = ['#2f6f76', '#d98c3f', '#6a994e', '#c0504d', '#4f6d7a'];
        const el = document.getElementById('hrDept');
        if (window.Chart && el) new Chart(el, { type:'bar', data:{ labels:c.labels,
            datasets: c.depts.map((d, i) => ({ label:d.name, data:d.data, backgroundColor:pal[i % pal.length], borderRadius:3 })) },
            options: Object.assign({}, opts, { plugins: Object.assign({}, opts.plugins, { legend:{ display:true, labels:{ font:{size:10}, boxWidth:10 } } }) }) });
    })();
    </script>
    @endpush
@endif
@endsection
