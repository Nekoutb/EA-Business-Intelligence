@php $R = \App\Services\Clearance\Rules::class; @endphp
<div class="clr-decide"><b>What to decide:</b> {{ $report['decide'] }}</div>

@foreach ($report['warnings'] as $w)
    <div class="clr-warn" role="alert">{{ $w }}</div>
@endforeach
@foreach (($report['notes'] ?? []) as $nt)
    <div class="clr-note">{{ $nt }}</div>
@endforeach

@if ($report['kpis'])
    <div class="clr-kpis">
        @foreach ($report['kpis'] as $k)
            @php
                $chg = null;
                if ($k['prev'] !== null && $k['value'] !== null && $k['fmt'] !== 'pct' && (float) $k['prev'] != 0.0) {
                    $chg = round(100 * ((float) $k['value'] - (float) $k['prev']) / abs((float) $k['prev']), 1);
                }
            @endphp
            <div class="clr-kpi {{ $k['flag'] }}">
                <div class="l">{{ $k['label'] }}</div>
                <div class="v">{{ $R::cell($k['value'], $k['fmt']) }}{{ $k['fmt'] === 'xaf' && $k['value'] !== null ? ' XAF' : '' }}</div>
                <div class="s">
                    @if ($k['prev'] !== null)
                        prev {{ $R::cell($k['prev'], $k['fmt']) }}
                        @if ($chg !== null)
                            <span class="{{ $chg >= 0 ? 'clr-up' : 'clr-down' }}">{{ $chg >= 0 ? '▲' : '▼' }} {{ number_format(abs($chg), 1) }} %</span>
                        @endif
                    @endif
                    {{ $k['sub'] ? ($k['prev'] !== null ? '· ' : '') . $k['sub'] : '' }}
                </div>
            </div>
        @endforeach
    </div>
@endif

@if ($report['charts'])
    <div class="clr-charts">
        @foreach ($report['charts'] as $chart)
            <div class="clr-card" style="margin-bottom:0;">
                <h3>{{ $chart['title'] }}</h3>
                @if (array_sum(array_map('abs', array_map('floatval', $chart['values']))) == 0)
                    <div class="clr-empty">No data in this window ({{ implode(' – ', [reset($chart['labels']), end($chart['labels'])]) }}).</div>
                @else
                    @include('clearance._chart', ['chart' => $chart])
                @endif
            </div>
        @endforeach
    </div>
@endif

@foreach ($report['sections'] as $s)
    <div class="clr-card">
        <h3>{{ $s['title'] }}</h3>
        @if (!$s['rows'])
            <div class="clr-empty">{{ $s['empty'] }}</div>
        @else
            <div class="clr-tablewrap">
                <table>
                    <thead><tr>
                        @foreach ($s['columns'] as [$key, $label, $fmt])
                            <th class="{{ in_array($fmt, ['xaf','int','pct','days','hours'], true) ? 'n' : '' }}">{{ $label }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($s['rows'] as $row)
                            <tr class="{{ $row['_flag'] ?? '' }}">
                                @foreach ($s['columns'] as [$key, $label, $fmt])
                                    <td class="{{ in_array($fmt, ['xaf','int','pct','days','hours'], true) ? 'n' : '' }}">{{ $R::cell($row[$key] ?? null, $fmt) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        @if ($s['total'])
                            <tr class="total">
                                @foreach ($s['columns'] as [$key, $label, $fmt])
                                    <td class="{{ in_array($fmt, ['xaf','int','pct','days','hours'], true) ? 'n' : '' }}">{{ $R::cell($s['total'][$key] ?? null, $fmt) }}</td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @endif
        @if ($s['note'])<div class="clr-note">{{ $s['note'] }}</div>@endif
    </div>
@endforeach

@if ($report['recon'])
    <div class="clr-card">
        <h3>Reconciliation</h3>
        <div class="clr-tablewrap">
            <table>
                <thead><tr><th>Check</th><th class="n">Report</th><th class="n">Ledger</th><th class="n">Difference</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($report['recon'] as $rc)
                        <tr class="{{ $rc['ok'] ? 'green' : 'red' }}">
                            <td>{{ $rc['label'] }}</td>
                            <td class="n">{{ $R::xaf($rc['report']) }}</td>
                            <td class="n">{{ $R::xaf($rc['ledger']) }}</td>
                            <td class="n">{{ $R::xaf($rc['difference']) }}</td>
                            <td>@if ($rc['ok'])<span class="clr-ok">OK</span>@else<span class="clr-bad">WARNING</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="clr-foot">
    Source: Odoo {{ $meta['db'] }}, extracted {{ $meta['extracted'] }}, by {{ $meta['user'] }}.
    Rows reflect what this API user is allowed to see (R-l): it must hold every Clearance group in Odoo for queue and file counts to be complete.
</div>
