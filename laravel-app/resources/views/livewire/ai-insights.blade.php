{{--
    Neat sticky AI advisor card.  Lives in the right rail.
    States: unconfigured → CTA to add Claude key.
            loading      → skeleton shimmer.
            error        → compact error with retry.
            ready        → 3–5 insight cards, colour-coded by severity.
--}}
<div class="p-5 space-y-4">

    {{-- Header --}}
    <div class="flex items-center gap-2.5">
        <div class="relative">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 via-fuchsia-500 to-amber-500 flex items-center justify-center shadow-lg shadow-violet-500/30">
                <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2L9.5 8.5 3 11l6.5 2.5L12 20l2.5-6.5L21 11l-6.5-2.5z"/>
                </svg>
            </div>
            @if ($loading)
              <span class="absolute -top-1 -right-1 flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-violet-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-violet-500"></span>
              </span>
            @endif
        </div>
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-semibold text-slate-900">Elite Advisor</h3>
            <p class="text-[11px] text-slate-500 leading-tight">
                AI-generated insights on this page
            </p>
        </div>
        @unless ($loading || $unconfigured)
          <button wire:click="generate"
                  class="text-slate-400 hover:text-violet-600 transition p-1 rounded"
                  title="Regenerate">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          </button>
        @endunless
    </div>

    {{-- ── Unconfigured state ─────────────────────────────────────────── --}}
    @if ($unconfigured)
        <div class="rounded-xl border border-dashed border-violet-300 bg-gradient-to-br from-violet-50 to-fuchsia-50 p-4 text-center space-y-2">
            <div class="text-xs text-slate-700">
                <strong>Elite Advisor</strong> isn't switched on yet for this platform.
            </div>
            <p class="text-[10px] text-slate-500 leading-tight">
                Your platform administrator can enable it from the super-admin backend.
            </p>
        </div>

    {{-- ── Loading skeleton ───────────────────────────────────────────── --}}
    @elseif ($loading)
        <div class="space-y-3">
          @for ($i = 0; $i < 3; $i++)
            <div class="rounded-lg border border-slate-200 p-3 space-y-2 bg-white">
                <div class="flex items-center gap-2">
                    <div class="skeleton h-4 w-4 rounded"></div>
                    <div class="skeleton h-3 w-32 rounded"></div>
                </div>
                <div class="skeleton h-2.5 w-full rounded"></div>
                <div class="skeleton h-2.5 w-5/6 rounded"></div>
                <div class="skeleton h-2.5 w-4/6 rounded"></div>
            </div>
          @endfor
        </div>
        <p class="text-[10px] text-slate-400 text-center italic">
            Insights and analysis by Elite advisors
        </p>

    {{-- ── Error ──────────────────────────────────────────────────────── --}}
    @elseif ($error)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 space-y-2">
            <div class="font-medium">Advisor unavailable</div>
            <div class="text-rose-600/80 break-words">{{ $error }}</div>
            <button wire:click="generate"
                    class="text-rose-700 underline hover:no-underline">Try again</button>
        </div>

    {{-- ── Ready: list of insights ────────────────────────────────────── --}}
    @else
        @php
            $sevMap = [
                'critical' => ['bg-rose-50',    'border-rose-200',    'text-rose-800',    'bg-rose-500',    '!'],
                'warning'  => ['bg-amber-50',   'border-amber-200',   'text-amber-800',   'bg-amber-500',   '△'],
                'info'     => ['bg-sky-50',     'border-sky-200',     'text-sky-800',     'bg-sky-500',     'i'],
                'positive' => ['bg-emerald-50', 'border-emerald-200', 'text-emerald-800', 'bg-emerald-500', '✓'],
                'good'     => ['bg-emerald-50', 'border-emerald-200', 'text-emerald-800', 'bg-emerald-500', '✓'],
            ];
        @endphp
        <div class="space-y-3">
          @forelse ($insights as $i => $insight)
              @php [$bg,$br,$tx,$dot,$icon] = $sevMap[$insight['severity']] ?? $sevMap['info']; @endphp
              <div class="rounded-lg border {{ $br }} {{ $bg }} p-3 space-y-1.5 animate-slide-up"
                   style="animation-delay: {{ $i * 60 }}ms">
                  <div class="flex items-start gap-2">
                      <span class="w-4 h-4 rounded-full {{ $dot }} text-white text-[9px] font-bold flex items-center justify-center shrink-0 mt-0.5">
                          {{ $icon }}
                      </span>
                      <h4 class="text-xs font-semibold {{ $tx }} leading-snug">
                          {{ $insight['title'] }}
                      </h4>
                  </div>
                  <p class="text-[11px] text-slate-700 leading-relaxed pl-6">
                      {{ $insight['body'] }}
                  </p>
              </div>
          @empty
              <p class="text-xs text-slate-500 italic text-center">
                  No actionable insights generated for this page.
              </p>
          @endforelse
        </div>

        <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-400">
            <span class="flex items-center gap-1">
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                Powered by Elite Advisors
            </span>
            <span>{{ ea_last_sync_label() }}</span>
        </div>
    @endif
</div>
