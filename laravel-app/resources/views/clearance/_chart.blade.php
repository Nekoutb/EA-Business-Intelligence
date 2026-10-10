@php
    $W = 560; $H = 210; $padL = 64; $padR = 14; $padT = 14; $padB = 36;
    $vals = array_map('floatval', $chart['values']);
    $n = max(1, count($vals));
    $max = max(1.0, ...($vals ?: [0]));
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $x = fn ($i) => $padL + ($n === 1 ? $plotW / 2 : $i * $plotW / ($n - 1));
    $y = fn ($v) => $padT + $plotH - ($v / $max) * $plotH;
    $fmt = fn ($v) => \App\Services\Clearance\Rules::cell($v, $chart['fmt']);
    $ticks = [0, $max / 2, $max];
@endphp
<svg viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" aria-label="{{ $chart['title'] }}" style="max-width:100%; height:auto; display:block;">
    @foreach ($ticks as $t)
        <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" stroke="#EEE8DD" stroke-width="1"/>
        <text x="{{ $padL - 6 }}" y="{{ $y($t) + 4 }}" text-anchor="end" font-size="10" fill="#8A8178">{{ $fmt($t) }}</text>
    @endforeach
    @if ($chart['type'] === 'line')
        @php
            $pts = [];
            foreach ($vals as $i => $v) { $pts[] = round($x($i), 1) . ',' . round($y($v), 1); }
        @endphp
        <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#3E5A44" stroke-width="2.25" stroke-linejoin="round"/>
        @foreach ($vals as $i => $v)
            <circle cx="{{ $x($i) }}" cy="{{ $y($v) }}" r="3.5" fill="#3E5A44"><title>{{ $chart['labels'][$i] }}: {{ $fmt($v) }}</title></circle>
            <text x="{{ $x($i) }}" y="{{ $H - $padB + 16 }}" text-anchor="middle" font-size="10" fill="#6B6157">{{ $chart['labels'][$i] }}</text>
        @endforeach
    @else
        @php $bw = min(70, $plotW / $n * 0.6); @endphp
        @foreach ($vals as $i => $v)
            @php $cx = $padL + ($i + 0.5) * $plotW / $n; @endphp
            <rect x="{{ $cx - $bw / 2 }}" y="{{ $y($v) }}" width="{{ $bw }}" height="{{ max(0, $padT + $plotH - $y($v)) }}" fill="#3E5A44" rx="2"><title>{{ $chart['labels'][$i] }}: {{ $fmt($v) }}</title></rect>
            <text x="{{ $cx }}" y="{{ $y($v) - 5 }}" text-anchor="middle" font-size="11" font-weight="700" fill="#14110F">{{ $fmt($v) }}</text>
            <text x="{{ $cx }}" y="{{ $H - $padB + 16 }}" text-anchor="middle" font-size="10" fill="#6B6157">{{ $chart['labels'][$i] }}</text>
        @endforeach
    @endif
</svg>
