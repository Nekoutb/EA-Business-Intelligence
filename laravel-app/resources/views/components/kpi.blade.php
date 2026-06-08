@props([
    'title'    => '',
    'value'    => '—',
    'delta'    => null,     // legacy badge (unused now but kept for compatibility)
    'sub'      => null,     // optional subtext (e.g. previous value)
    'hint'     => null,     // optional plain-language tooltip for jargon (DSO, DPO, …)
    'color'    => 'brand',  // brand|sage|warm|coral|slate
    'icon'     => null,     // optional SVG <path d="…"/> string
    'vsPy'     => null,
    'vsPlan'   => null,
    'prefer'   => 'up',
])

@php
    $palettes = [
        'brand'   => ['bg-brand-500',  'text-brand-600',  'bg-brand-50',  'border-brand-100'],
        'indigo'  => ['bg-brand-500',  'text-brand-600',  'bg-brand-50',  'border-brand-100'],
        'sage'    => ['bg-sage-500',   'text-sage-600',   'bg-sage-50',   'border-sage-100'],
        'emerald' => ['bg-sage-500',   'text-sage-600',   'bg-sage-50',   'border-sage-100'],
        'warm'    => ['bg-warm-500',   'text-warm-600',   'bg-warm-50',   'border-warm-100'],
        'amber'   => ['bg-warm-500',   'text-warm-600',   'bg-warm-50',   'border-warm-100'],
        'coral'   => ['bg-coral-500',  'text-coral-600',  'bg-coral-50',  'border-coral-100'],
        'rose'    => ['bg-coral-500',  'text-coral-600',  'bg-coral-50',  'border-coral-100'],
        'slate'   => ['bg-slate-400',  'text-slate-500',  'bg-slate-50',  'border-slate-100'],
    ];
    [$bar, $txt, $bg, $bdr] = $palettes[$color] ?? $palettes['brand'];
    $deltaColor = is_numeric($delta)
        ? ($delta >= 0 ? 'text-sage-700 bg-sage-50' : 'text-coral-700 bg-coral-50')
        : 'text-slate-500 bg-slate-50';

    $formatPct = function (?float $val): ?string {
        if (!is_numeric($val)) return null;
        $sign = $val >= 0 ? '+' : '';
        return $sign . number_format((float) $val, 1) . '%';
    };

    $trendMeta = function (?float $val, string $prefer) {
        if (!is_numeric($val)) return null;
        $good = $prefer === 'down' ? $val <= 0 : $val >= 0;
        return [
            'color' => $good ? 'text-sage-700' : 'text-coral-700',
            'icon'  => $good ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z',
            'value' => $val,
        ];
    };

    $vsPyMeta   = $trendMeta($vsPy, $prefer);
    $vsPlanMeta = $trendMeta($vsPlan, $prefer);
@endphp

<div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm relative overflow-hidden flex">
    <div class="accent-bar {{ $bar }} shrink-0"></div>
    <div class="flex-1 min-w-0 p-4">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium flex items-center gap-1 min-w-0">
                    <span class="truncate">{{ $title }}</span>
                    @if ($hint)
                      <span class="shrink-0 w-3.5 h-3.5 rounded-full bg-[#e8e4de] text-[#6b6457] text-[9px] font-bold leading-none flex items-center justify-center cursor-help"
                            title="{{ $hint }}" aria-label="{{ $title }} — {{ $hint }}" role="img">?</span>
                    @endif
                </div>
                <div class="text-xl font-bold text-[#2d3748] mt-1 tabular-nums truncate">{{ $value }}</div>
                @if ($sub)
                  <div class="text-[11px] text-[#a09d95] mt-0.5 truncate">{{ $sub }}</div>
                @endif
            </div>
            @if ($icon)
              <div class="w-7 h-7 rounded-md {{ $bg }} {{ $bdr }} border flex items-center justify-center shrink-0">
                  <svg class="w-3.5 h-3.5 {{ $txt }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="{{ $icon }}"/>
                  </svg>
              </div>
            @endif
        </div>

        @if (is_numeric($delta))
          <div class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded {{ $deltaColor }}">
              <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  @if ($delta >= 0) <path d="M7 17l10-10M7 7h10v10"/>
                  @else              <path d="M7 7l10 10M17 7v10H7"/>
                  @endif
              </svg>
              {{ ($delta >= 0 ? '+' : '') . number_format((float) $delta, 1) }}%
          </div>
        @endif

        @if ($vsPyMeta || $vsPlanMeta)
          {{-- vs-PY / vs-Plan tags: deliberately one notch larger than the
               legacy delta badge (11px vs 10px) so the comparative read is
               actually scannable at a glance. Matches the title/sub sizing
               so the line reads as a peer to the metric, not a footnote. --}}
          <div class="mt-2 text-[11px] font-semibold flex flex-wrap items-center gap-x-1.5 gap-y-1 leading-tight">
              @if ($vsPyMeta)
                  <span class="inline-flex items-center gap-1 {{ $vsPyMeta['color'] }}">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                          <path d="{{ $vsPyMeta['icon'] }}" />
                      </svg>
                      {{ $formatPct($vsPyMeta['value']) }} <span class="font-normal text-[#7c7466]">vs PY</span>
                  </span>
              @else
                  <span class="text-[#bcb6ab]">— vs PY</span>
              @endif

              <span class="text-[#cec8bc]">/</span>

              @if ($vsPlanMeta)
                  <span class="inline-flex items-center gap-1 {{ $vsPlanMeta['color'] }}">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                          <path d="{{ $vsPlanMeta['icon'] }}" />
                      </svg>
                      {{ $formatPct($vsPlanMeta['value']) }} <span class="font-normal text-[#7c7466]">vs Plan</span>
                  </span>
              @else
                  <span class="text-[#bcb6ab]">— vs Plan</span>
              @endif
          </div>
        @endif
    </div>
</div>
