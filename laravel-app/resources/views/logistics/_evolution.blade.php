{{-- Evolution chart for the Volumes page: three stacked line charts
     (tonnage, containers, packages) on one shared x-axis. Pure inline SVG,
     no library — same approach as the dashboard trend. Expects $series
     (from LogisticsOpsService::volumes) and $grain. --}}
@php
  $metrics = [
    ['key' => 'tons',       'label' => 'Tonnage',    'unit' => 't',   'color' => '#0d6e6e'],
    ['key' => 'containers', 'label' => 'Containers', 'unit' => 'ctn', 'color' => '#1e3d44'],
    ['key' => 'packages',   'label' => 'Packages',   'unit' => 'colis', 'color' => '#b7791f'],
  ];
  $n  = count($series);
  $W  = 640; $H = 96; $padL = 44; $padR = 10; $padT = 8; $padB = 4;
  $iw = $W - $padL - $padR; $ih = $H - $padT - $padB;
  $xOf = fn (int $i) => $n > 1 ? $padL + $iw * $i / ($n - 1) : $padL + $iw / 2;
  // Label every bucket for months, every ~7th for days, all for weeks.
  $every = $grain === 'day' ? max(1, (int) ceil($n / 10)) : 1;
  $fmt = fn (float $v) => $v >= 1000 ? number_format($v / 1000, 1) . 'k' : number_format($v, $v < 10 && $v != floor($v) ? 1 : 0);
@endphp
<style>
  .lg-evo { display:grid; gap:6px; }
  .lg-evo .row { display:grid; grid-template-columns:96px 1fr; gap:8px; align-items:center; }
  .lg-evo .row .m { font-size:11px; color:#5c6a66; line-height:1.3; }
  .lg-evo .row .m b { display:block; font-size:15px; color:#1e3d44; font-variant-numeric:tabular-nums; }
  .lg-evo svg { width:100%; height:auto; display:block; }
  .lg-evo .axis { display:grid; grid-template-columns:96px 1fr; gap:8px; }
  .lg-evo .axis svg { width:100%; height:auto; }
  .lg-evo text { font-size:9.5px; fill:#7c7466; font-family:inherit; }
  .lg-evo .pt:hover { r:4.5; }
  .lg-grain { display:inline-flex; gap:4px; float:right; }
  .lg-grain a { font-size:10.5px; letter-spacing:.06em; text-transform:uppercase; padding:3px 9px; border-radius:999px; border:1px solid #e8e4de; color:#7c7466; text-decoration:none; }
  .lg-grain a.on { background:#1e3d44; color:#fff; border-color:#1e3d44; }
</style>
<div class="lg-evo">
  @foreach ($metrics as $m)
    @php
      $vals = array_map(fn ($r) => (float) $r[$m['key']], $series);
      $max  = max($vals ?: [0]) ?: 1;
      $total = array_sum($vals);
      $pts = [];
      foreach ($vals as $i => $v) $pts[] = round($xOf($i), 1) . ',' . round($padT + $ih - $ih * $v / $max, 1);
      $line = implode(' ', $pts);
      $area = $n ? ($line . ' ' . round($xOf($n - 1), 1) . ',' . ($padT + $ih) . ' ' . round($xOf(0), 1) . ',' . ($padT + $ih)) : '';
    @endphp
    <div class="row">
      <div class="m">{{ $m['label'] }}<b>{{ number_format($total) }} {{ $m['unit'] }}</b></div>
      <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" aria-label="{{ $m['label'] }} evolution">
        <line x1="{{ $padL }}" y1="{{ $padT + $ih }}" x2="{{ $W - $padR }}" y2="{{ $padT + $ih }}" stroke="#efece6"/>
        <line x1="{{ $padL }}" y1="{{ $padT }}" x2="{{ $W - $padR }}" y2="{{ $padT }}" stroke="#efece6" stroke-dasharray="2 3"/>
        <text x="{{ $padL - 4 }}" y="{{ $padT + 4 }}" text-anchor="end">{{ $fmt($max) }}</text>
        <text x="{{ $padL - 4 }}" y="{{ $padT + $ih }}" text-anchor="end">0</text>
        @if ($n > 1)
          <polygon points="{{ $area }}" fill="{{ $m['color'] }}" fill-opacity=".08"/>
          <polyline points="{{ $line }}" fill="none" stroke="{{ $m['color'] }}" stroke-width="1.8" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
        @endif
        @foreach ($series as $i => $r)
          <circle class="pt" cx="{{ round($xOf($i), 1) }}" cy="{{ round($padT + $ih - $ih * (float) $r[$m['key']] / $max, 1) }}" r="2.6" fill="{{ $m['color'] }}">
            <title>{{ $r['bucket'] }} · {{ number_format((float) $r[$m['key']], $m['key'] === 'tons' ? 1 : 0) }} {{ $m['unit'] }} · {{ $r['files'] }} files</title>
          </circle>
        @endforeach
      </svg>
    </div>
  @endforeach
  <div class="axis"><span></span>
    <svg viewBox="0 0 {{ $W }} 14" preserveAspectRatio="none">
      @foreach ($series as $i => $r)
        @if ($i % $every === 0 || $i === $n - 1)
          <text x="{{ round($xOf($i), 1) }}" y="11" text-anchor="{{ $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') }}">{{ $r['label'] }}</text>
        @endif
      @endforeach
    </svg>
  </div>
</div>
