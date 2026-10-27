{{-- Margin on débours: billed débours lines vs débours paid out, per file invoiced in the year. Expects $dm, $year. --}}
@php
    $m = fn ($v) => $v === null ? '—' : (($v < 0 ? '−' : '') . number_format(abs((float) $v), 0, '.', ' '));
    $ms = function ($v) {
        if ($v === null) return '—';
        $a = abs((float) $v); $s = $v < 0 ? '−' : '';
        return $s . ($a >= 1e9 ? number_format($a / 1e9, 2) . ' bn' : ($a >= 1e6 ? number_format($a / 1e6, 1) . ' M' : ($a >= 1e4 ? number_format($a / 1e3, 0) . ' K' : number_format($a, 0, '.', ' '))));
    };
    $pc = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . ' %';
    $tone = fn ($v) => $v === null ? '' : ($v < -0.5 ? 'text-rose-700' : ($v > 0.5 ? 'text-emerald-700' : 'text-[#7c7466]'));
    $card = 'bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-4';
    $h2 = 'text-sm font-semibold text-[#1e3d44]';
    $note = 'text-[11px] text-[#7c7466] mb-3';
    $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
    $td = 'px-2 py-1.5 text-[12px] text-[#2d3748]';
@endphp
<div class="mt-8">
    <div class="flex items-baseline justify-between gap-3 flex-wrap mb-1">
        <h2 class="text-base font-semibold text-[#1e3d44]">Margin on débours · {{ $year }}</h2>
        <div class="text-[11px] text-[#7c7466]">Clearance application · files first invoiced in {{ $year }}</div>
    </div>
    <p class="text-[12px] text-[#5b5547] mb-4 max-w-4xl">Débours are paid for clients and recharged on their invoices. <b>Billed</b> = the débours lines of the invoices (service lines excluded), credit notes deducted; <b>incurred</b> = what was actually paid out on the file.
        The margin is billed − incurred: positive when débours are recharged above cost, negative when the company absorbs part of them. Each file is measured once invoiced, so work still in progress never shows as a loss.</p>

    @if (!empty($dm['error']))
        <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm mb-4">{{ $dm['error'] }}</div>
    @else
        @php $S = $dm['summary']; $M = $dm['months']; @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-4">
            @foreach ([['Débours incurred', $ms($S['incurred']), $S['files'] . ' files invoiced', ''],
                       ['Débours billed', $ms($S['billed']), 'débours lines on invoices', ''],
                       ['Margin on débours', $ms($S['margin']), $pc($S['margin_pct']) . ' of incurred', $tone($S['margin'])],
                       ['Gains', $ms($S['gain']), $S['gain_files'] . ' files recharged above cost', 'text-emerald-700'],
                       ['Losses', $ms($S['loss']), $S['loss_files'] . ' files recharged below cost', $S['loss_files'] ? 'text-rose-700' : ''],
                       ['Customers', $S['clients_profitable'] . ' / ' . $S['clients_lossmaking'], 'profitable / loss-making · ' . $S['clients_even'] . ' at cost', '']] as [$l, $v, $sub, $cls])
                <div class="bg-white rounded-xl border border-[#e8e4de] p-3 shadow-sm">
                    <div class="text-[11px] font-semibold text-[#5b5547]">{{ $l }}</div>
                    <div class="text-[20px] font-semibold tabular-nums {{ $cls ?: 'text-[#1e3d44]' }}">{{ $v }}</div>
                    <div class="text-[11px] text-[#8a8578]">{{ $sub }}</div>
                </div>
            @endforeach
        </div>

        @if (!empty($dm['no_cost']['files']))
            <details class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-4 text-[12px] text-amber-900">
                <summary class="cursor-pointer"><b>{{ $dm['no_cost']['files'] }} invoiced file{{ $dm['no_cost']['files'] > 1 ? 's' : '' }} left out</b>: their invoices recharge {{ $m($dm['no_cost']['billed']) }} of débours, but no disbursement was paid out on them in the clearance application (paid before go-live or outside it), so there is no cost to measure a margin against.</summary>
                <div class="mt-2 max-h-64 overflow-y-auto">
                    @foreach ($dm['no_cost']['rows'] as $r)
                        <div class="flex justify-between gap-3 border-t border-amber-200 py-1"><span>{{ $r['file'] }} · {{ $r['client'] }} · invoiced {{ \Carbon\Carbon::parse($r['invoiced'])->format('d/m/Y') }}</span><span class="tabular-nums">{{ $m($r['billed']) }}</span></div>
                    @endforeach
                </div>
            </details>
        @endif

        {{-- Sandbox 1 — by customer --}}
        @php
            $allC = array_merge($dm['profitable'], $dm['lossmaking'], $dm['even']);
            usort($allC, fn ($a, $b) => $b['incurred'] <=> $a['incurred']);
            $topC = array_slice($allC, 0, 12);
        @endphp
        <div class="{{ $card }}">
            <h3 class="{{ $h2 }}">1 · Débours billed vs incurred per customer</h3>
            <p class="{{ $note }}">The 12 customers with the most débours: what was paid out for them against what their invoices recharged.</p>
            <div class="h-72 mb-4"><canvas id="dmClients"></canvas></div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-[#faf9f6]"><tr>
                        <th class="{{ $th }} text-left">Customer</th><th class="{{ $th }} text-right">Files</th><th class="{{ $th }} text-right">Incurred</th>
                        <th class="{{ $th }} text-right">Billed</th><th class="{{ $th }} text-right">Margin</th><th class="{{ $th }} text-right">Margin %</th>
                        <th class="{{ $th }} text-right">Files at a loss</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($allC as $c)
                        <tr class="border-t border-[#f1ede5]">
                            <td class="{{ $td }} font-medium">{{ $c['client'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $c['files'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $m($c['incurred']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $m($c['billed']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums font-semibold {{ $tone($c['margin']) }}">{{ $m($c['margin']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums {{ $tone($c['margin']) }}">{{ $pc($c['margin_pct']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums {{ $c['loss_files'] ? 'text-rose-700' : '' }}">{{ $c['loss_files'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="{{ $td }} text-[#8a8578] py-4">No file with débours was invoiced in {{ $year }} yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Sandbox 2 — monthly performance --}}
        <div class="{{ $card }}">
            <h3 class="{{ $h2 }}">2 · Monthly performance</h3>
            <p class="{{ $note }}">Files grouped by the month of their first invoice: débours incurred and billed, the margin, and how much of it was gains and losses.</p>
            <div class="h-64 mb-4"><canvas id="dmMonthly"></canvas></div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-[#faf9f6]"><tr>
                        <th class="{{ $th }} text-left">Month</th><th class="{{ $th }} text-right">Files</th><th class="{{ $th }} text-right">Incurred</th>
                        <th class="{{ $th }} text-right">Billed</th><th class="{{ $th }} text-right">Gains</th><th class="{{ $th }} text-right">Losses</th>
                        <th class="{{ $th }} text-right">Margin</th><th class="{{ $th }} text-right">Margin %</th><th class="{{ $th }} text-right">Files at a loss</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($M as $r)
                        <tr class="border-t border-[#f1ede5]">
                            <td class="{{ $td }}">{{ $r['label'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['files'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $m($r['incurred']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $m($r['billed']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums text-emerald-700">{{ $r['gain'] ? $m($r['gain']) : '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums text-rose-700">{{ $r['loss'] ? $m($r['loss']) : '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums font-semibold {{ $tone($r['margin']) }}">{{ $r['files'] ? $m($r['margin']) : '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $pc($r['margin_pct']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums {{ $r['loss_files'] ? 'text-rose-700' : '' }}">{{ $r['loss_files'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Sandbox 3 — trend --}}
        <div class="{{ $card }}">
            <h3 class="{{ $h2 }}">3 · Trend since January</h3>
            <p class="{{ $note }}">Margin % of each month (left) and the margin accumulated since the start of the year (right). A falling line means débours are increasingly recharged below cost.</p>
            <div class="h-64"><canvas id="dmTrend"></canvas></div>
        </div>

        {{-- Sandbox 4 — profitable vs loss-making customers, with drilldown --}}
        <div class="{{ $card }}">
            <h3 class="{{ $h2 }}">4 · Where débours make money, and where they lose it</h3>
            <p class="{{ $note }}">Customers split by the result of their débours. Open a customer to see each invoiced file: paid out, recharged and the margin.</p>
            <div class="grid lg:grid-cols-2 gap-4">
                @foreach ([['Loss-making customers', $dm['lossmaking'], 'border-rose-200', 'text-rose-800'], ['Profitable customers', $dm['profitable'], 'border-emerald-200', 'text-emerald-800']] as [$title, $list, $bdr, $tc])
                    <div class="border {{ $bdr }} rounded-lg">
                        <div class="px-3 py-2 text-[12px] font-semibold {{ $tc }} border-b {{ $bdr }}">{{ $title }} · {{ count($list) }} · {{ $m(array_sum(array_column($list, 'margin'))) }}</div>
                        @forelse ($list as $c)
                            <details class="border-t border-[#f1ede5] first:border-t-0">
                                <summary class="cursor-pointer px-3 py-2 text-[12px] flex justify-between gap-2 hover:bg-[#faf9f6]">
                                    <span class="font-medium">{{ $c['client'] }} <span class="text-[#8a8578] font-normal">· {{ $c['files'] }} file{{ $c['files'] > 1 ? 's' : '' }}</span></span>
                                    <span class="tabular-nums font-semibold {{ $tone($c['margin']) }}">{{ $m($c['margin']) }} <span class="font-normal">({{ $pc($c['margin_pct']) }})</span></span>
                                </summary>
                                <div class="px-3 pb-3 overflow-x-auto">
                                    <table class="w-full">
                                        <thead><tr><th class="{{ $th }} text-left">File</th><th class="{{ $th }} text-left">Invoiced</th><th class="{{ $th }} text-right">Incurred</th><th class="{{ $th }} text-right">Billed</th><th class="{{ $th }} text-right">Margin</th></tr></thead>
                                        <tbody>
                                        @foreach ($c['rows'] as $r)
                                            <tr class="border-t border-[#f1ede5]">
                                                <td class="{{ $td }}" title="{{ $r['invoices'] }}">{{ $r['file'] }}</td>
                                                <td class="{{ $td }}">{{ \Carbon\Carbon::parse($r['invoiced'])->format('d/m/Y') }}</td>
                                                <td class="{{ $td }} text-right tabular-nums">{{ $m($r['incurred']) }}</td>
                                                <td class="{{ $td }} text-right tabular-nums">{{ $m($r['billed']) }}</td>
                                                <td class="{{ $td }} text-right tabular-nums font-semibold {{ $tone($r['margin']) }}">{{ $m($r['margin']) }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @empty
                            <div class="px-3 py-3 text-[12px] text-[#8a8578]">None.</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
            @if ($dm['even'])
                <p class="text-[11px] text-[#7c7466] mt-3">Recharged exactly at cost: {{ implode(', ', array_column($dm['even'], 'client')) }}.</p>
            @endif

            @if ($dm['loss_files'])
                <h4 class="text-[12px] font-semibold text-rose-800 mt-5 mb-1">Largest losses by file</h4>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left">File</th><th class="{{ $th }} text-left">Customer</th><th class="{{ $th }} text-left">Invoices</th>
                            <th class="{{ $th }} text-right">Incurred</th><th class="{{ $th }} text-right">Billed</th><th class="{{ $th }} text-right">Loss</th><th class="{{ $th }} text-right">% of incurred</th></tr></thead>
                        <tbody>
                        @foreach ($dm['loss_files'] as $r)
                            <tr class="border-t border-[#f1ede5]">
                                <td class="{{ $td }} font-medium">{{ $r['file'] }}</td><td class="{{ $td }}">{{ $r['client'] }}</td>
                                <td class="{{ $td }} text-[11px] text-[#7c7466]">{{ $r['invoices'] }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $m($r['incurred']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $m($r['billed']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums font-semibold text-rose-700">{{ $m($r['margin']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-rose-700">{{ $pc($r['margin_pct']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @php
            $dmC = array_map(fn ($c) => ['n' => $c['client'], 'i' => round($c['incurred']), 'b' => round($c['billed'])], $topC);
            $dmM = array_values(array_map(fn ($r) => ['l' => $r['label'], 'i' => round($r['incurred']), 'b' => round($r['billed']), 'm' => round($r['margin']), 'p' => $r['margin_pct'], 'c' => round($r['cumulative']), 'f' => $r['files']], $M));
        @endphp
        @push('scripts')
        <script>
        (function () {
            const C = @json($dmC);
            const M = @json($dmM);
            const fmt = v => { const a = Math.abs(+v || 0), s = v < 0 ? '−' : ''; return s + (a >= 1e9 ? (a/1e9).toFixed(2)+'B' : a >= 1e6 ? (a/1e6).toFixed(1)+'M' : a >= 1e3 ? (a/1e3).toFixed(0)+'K' : Math.round(a)); };
            const base = { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { font: { size: 11 }, usePointStyle: true } }, tooltip: { backgroundColor: '#1e3d44', callbacks: { label: c => c.dataset.label + ': ' + (c.dataset.pct ? (c.parsed.y ?? '—') + ' %' : fmt(c.parsed.y ?? c.parsed.x)) } } } };
            function go() {
                if (!window.Chart) return setTimeout(go, 150);
                new Chart(document.getElementById('dmClients'), { type: 'bar',
                    data: { labels: C.map(x => x.n), datasets: [
                        { label: 'Incurred', data: C.map(x => x.i), backgroundColor: '#d98c3f', borderRadius: 2 },
                        { label: 'Billed', data: C.map(x => x.b), backgroundColor: '#2f6f76', borderRadius: 2 } ] },
                    options: Object.assign({}, base, { indexAxis: 'y', scales: { x: { grid: { color: '#f1ede5' }, ticks: { font: { size: 10 }, callback: fmt } }, y: { grid: { display: false }, ticks: { font: { size: 10 } } } } }) });
                new Chart(document.getElementById('dmMonthly'), {
                    data: { labels: M.map(x => x.l), datasets: [
                        { type: 'bar', label: 'Incurred', data: M.map(x => x.i), backgroundColor: '#d98c3f', borderRadius: 2, order: 2 },
                        { type: 'bar', label: 'Billed', data: M.map(x => x.b), backgroundColor: '#2f6f76', borderRadius: 2, order: 2 },
                        { type: 'line', label: 'Margin', data: M.map(x => x.f ? x.m : null), borderColor: '#b23a2b', backgroundColor: '#b23a2b', tension: .3, borderWidth: 2, pointRadius: 3, order: 1 } ] },
                    options: Object.assign({}, base, { scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { grid: { color: '#f1ede5' }, ticks: { font: { size: 10 }, callback: fmt } } } }) });
                new Chart(document.getElementById('dmTrend'), {
                    data: { labels: M.map(x => x.l), datasets: [
                        { type: 'line', label: 'Margin %', pct: true, data: M.map(x => x.p), borderColor: '#2f6f76', backgroundColor: '#2f6f76', tension: .3, borderWidth: 2, pointRadius: 3, yAxisID: 'y' },
                        { type: 'line', label: 'Cumulative margin', data: M.map(x => x.c), borderColor: '#b07f3b', backgroundColor: 'rgba(176,127,59,.12)', fill: true, tension: .3, borderWidth: 2, pointRadius: 0, yAxisID: 'y2' } ] },
                    options: Object.assign({}, base, { scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                        y: { position: 'left', grid: { color: '#f1ede5' }, ticks: { font: { size: 10 }, callback: v => v + ' %' } },
                        y2: { position: 'right', grid: { display: false }, ticks: { font: { size: 10 }, callback: fmt } } } }) });
            }
            go();
        })();
        </script>
        @endpush
    @endif
</div>
