@php
    $ciQs = $filter ? '?' . http_build_query($filter) : '';
    $ciInp = 'text-[12px] px-2 py-1.5 border border-[#e8e4de] rounded-md bg-white';
    $ciBtn = 'text-[12px] font-semibold px-3 py-1.5 rounded-md border border-[#e8e4de] bg-white hover:bg-[#f5f2ec] text-[#1e3d44]';
    $ciToday = now('Africa/Douala')->format('Y-m-d');
@endphp
<nav class="flex flex-wrap gap-1 mb-4 border-b border-[#e8e4de] pb-2" aria-label="Clearance Intelligence">
    @foreach (\App\Http\Controllers\ClearanceIntelController::PAGES as $p => [$mod, $lbl])
        @if (tenant()->moduleEnabled($mod))
            <a href="{{ tenant_url('/clearance/' . $p) }}{{ $ciQs }}"
               class="px-3 py-1.5 text-[12px] font-semibold rounded-md {{ $page === $p ? 'bg-brand-600 text-white' : 'text-[#5b5547] hover:bg-[#f5f2ec]' }}">{{ $lbl }}</a>
        @endif
    @endforeach
</nav>

<div class="flex flex-wrap items-end justify-between gap-3 mb-4">
    <div>
        <div class="text-[11px] uppercase tracking-wide text-[#8a8578] font-semibold">Clearance Intelligence · {{ $periodLabel }}</div>
        <h1 class="text-xl font-semibold text-[#1e3d44]">{{ $title }}</h1>
        @if (!empty($data))
            <div class="text-[11px] text-[#7c7466]">
                {{ \App\Services\ClearanceIntel\Fmt::d($data['from']) }} – {{ \App\Services\ClearanceIntel\Fmt::d($data['to']) }}
                · compared with {{ \App\Services\ClearanceIntel\Fmt::d($data['prev_from']) }} – {{ \App\Services\ClearanceIntel\Fmt::d($data['prev_to']) }}
                · data as of {{ $data['extracted'] }} · read-only from the clearance application
            </div>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ tenant_url('/clearance/' . $page) }}" class="{{ $ciBtn }}">Year to date</a>
        <form method="GET" class="flex items-center gap-1">
            <label for="ci-month" class="text-[11px] text-[#7c7466]">Month</label>
            <input id="ci-month" type="month" name="period" value="{{ $filter['period'] ?? '' }}" class="{{ $ciInp }}">
            <button class="{{ $ciBtn }}">Apply</button>
        </form>
        <form method="GET" class="flex items-center gap-1" title="One day: set the first date only. A range: set both.">
            <label for="ci-from" class="text-[11px] text-[#7c7466]">Days</label>
            <input id="ci-from" type="date" name="from" value="{{ $filter['from'] ?? '' }}" max="{{ $ciToday }}" required class="{{ $ciInp }}">
            <span class="text-[11px] text-[#7c7466]">to</span>
            <input type="date" name="to" value="{{ ($filter['to'] ?? '') !== ($filter['from'] ?? '') ? ($filter['to'] ?? '') : '' }}" max="{{ $ciToday }}" class="{{ $ciInp }}">
            <button class="{{ $ciBtn }}">Apply</button>
        </form>
        <a href="{{ tenant_url('/clearance/' . $page) }}?{{ http_build_query($filter + ['refresh' => 1]) }}" class="{{ $ciBtn }}" title="Re-read the clearance application now">↻ Refresh</a>
    </div>
</div>

@if (!empty($error))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm mb-4" role="alert">
        The report could not be produced: {{ $error }}
        <div class="text-[12px] text-rose-700 mt-1">Clearance Intelligence only reads the clearance application and checks every field it needs first; nothing was changed in Odoo.</div>
    </div>
@endif
@if (!empty($data['warnings']))
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-3 text-[12px] mb-4">
        @foreach ($data['warnings'] as $w)<div>{{ $w }}</div>@endforeach
    </div>
@endif

@once
@push('scripts')
<script>
(function () {
    var PAL = ['#1e3d44', '#b07f3b', '#5b8c85', '#d9a866', '#8a8578', '#c0392b', '#2d6a4f', '#7a5195'];
    function money(v) {
        var a = Math.abs(v), s = v < 0 ? '−' : '';
        if (a >= 1e9) return s + (a / 1e9).toFixed(2) + ' bn';
        if (a >= 1e6) return s + (a / 1e6).toFixed(1) + ' M';
        if (a >= 1e4) return s + Math.round(a / 1e3) + ' K';
        return s + Math.round(a);
    }
    function init() {
        if (!window.Chart) return setTimeout(init, 150);
        document.querySelectorAll('canvas[data-ci]').forEach(function (cv) {
            if (cv._ci) return;
            var c = JSON.parse(cv.getAttribute('data-ci'));
            var fmt = c.fmt === 'xaf' ? money : function (v) { return (Math.round(v * 10) / 10).toLocaleString('fr-FR') + (c.fmt === 'pct' ? ' %' : c.fmt === 'days' ? ' d' : ''); };
            c.datasets.forEach(function (d, i) {
                var col = d.color || PAL[i % PAL.length];
                d.borderColor = d.borderColor || col;
                d.backgroundColor = d.backgroundColor || (d.type === 'line' || c.type === 'line' ? col : col);
                if ((d.type || c.type) === 'line') { d.tension = .3; d.pointRadius = d.pointRadius ?? 2; d.borderWidth = 2; d.fill = false; }
                if ((d.type || c.type) === 'bar') { d.borderRadius = 2; }
            });
            cv._ci = new Chart(cv, {
                type: c.type, data: { labels: c.labels, datasets: c.datasets },
                options: {
                    maintainAspectRatio: false, indexAxis: c.horizontal ? 'y' : 'x',
                    plugins: { legend: { display: c.datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                               tooltip: { callbacks: { label: function (x) { return ' ' + (x.dataset.label || '') + ' ' + fmt(c.horizontal ? x.parsed.x : x.parsed.y); } } } },
                    scales: c.type === 'doughnut' ? {} : {
                        x: { stacked: !!c.stacked, grid: { display: !!c.horizontal, color: '#f1ede5' }, ticks: { font: { size: 11 }, callback: c.horizontal ? function (v) { return fmt(v); } : undefined } },
                        y: { stacked: !!c.stacked, grid: { display: !c.horizontal, color: '#f1ede5' }, beginAtZero: true, ticks: { font: { size: 11 }, callback: c.horizontal ? undefined : function (v) { return fmt(v); } } }
                    }
                }
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
@endpush
@endonce
