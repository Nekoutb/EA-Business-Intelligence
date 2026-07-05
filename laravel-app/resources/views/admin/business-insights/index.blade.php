@extends('admin.layout')
@section('title', 'Client Strategic Analysis')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Client Strategic Analysis</h1>
            <p class="text-xs text-slate-500 mt-1">
                Monthly Claude-generated strategic audit for each client — a holistic diagnosis across profitability,
                liquidity, growth, efficiency and risk. Each card shows the 5 headline KPIs as traffic-light dots.
                Click into any client for the full narrative, prioritized action plan, 90-day roadmap, and a downloadable report.
            </p>
        </div>
        <form method="POST" action="/admin/business-insights/run-all" onsubmit="return confirm('Regenerate insights for ALL tenants now? This will call the LLM once per tenant.')">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 rounded-lg px-3.5 py-2 shadow transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Regenerate all now
            </button>
        </form>
    </div>
@endsection

@section('content')
@php
  use App\Services\BusinessInsightsScorer;

  $totalOk      = $latest->where('status', 'ok')->count();
  $totalPartial = $latest->where('status', 'partial')->count();
  $totalUrgent  = $latest->where('priority', 'urgent')->count();
  $totalWatch   = $latest->where('priority', 'watch')->count();
  $totalGood    = $latest->where('priority', 'good')->count();
  $totalFailed  = $latest->where('status', 'failed')->count();
  $totalSkipped = $latest->where('status', 'skipped')->count();

  // Build a per-tenant scorecard lookup ahead of the loop so we don't
  // recompute inside the inner render. Older insight rows that pre-date
  // the scorecard wiring get re-scored on the fly by BusinessInsight::scorecard().
  $scorecards = $latest->map(fn ($row) => $row?->scorecard() ?? []);

  // KPI order for the 5-dot status strip — must match the show page so
  // both surfaces show KPIs in the same slots.
  $kpiOrder = [
      'revenue_yoy'   => 'YoY',
      'gross_margin'  => 'GM',
      'ebitda_margin' => 'EBITDA',
      'dso'           => 'DSO',
      'overdue_rate'  => 'OVD',
  ];

  // Risk-count summary across the fleet
  $totalUrgentRisks = $scorecards->sum(fn ($s) => collect($s['risks'] ?? [])->where('severity', 'urgent')->count());
@endphp

{{-- Summary band ─────────────────────────────────────────── --}}
<div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-5">
    @foreach ([
        ['Tenants',       count($tenants), 'from-slate-500 to-slate-700'],
        ['Urgent',        $totalUrgent,    'from-rose-500 to-orange-500'],
        ['Watch',         $totalWatch,     'from-amber-500 to-yellow-500'],
        ['Good',          $totalGood,      'from-emerald-500 to-teal-500'],
        ['Urgent risks',  $totalUrgentRisks, 'from-rose-600 to-pink-600'],
        ['Pending',       $totalSkipped + $totalFailed + (count($tenants) - $latest->count()), 'from-violet-500 to-indigo-500'],
    ] as [$label, $value, $grad])
      <div class="panel p-4">
          <div class="flex items-center gap-3">
              <div class="w-1 h-10 rounded bg-gradient-to-b {{ $grad }}"></div>
              <div>
                  <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium">{{ $label }}</p>
                  <div class="text-xl font-bold text-slate-900">{{ number_format($value) }}</div>
              </div>
          </div>
      </div>
    @endforeach
</div>

{{-- KPI legend ─────────────────────────────────────────── --}}
<div class="panel p-3 mb-5 flex items-center flex-wrap gap-x-5 gap-y-2 text-[11px] text-slate-600">
    <span class="font-semibold uppercase tracking-wider text-slate-500 text-[10px]">KPI thresholds:</span>
    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Green = within target</span>
    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Amber = trending wrong</span>
    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-rose-500"></span> Red = below threshold</span>
    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-slate-300"></span> No data</span>
    <span class="ml-auto text-slate-400 italic">Slots: YoY · GM · EBITDA · DSO · OVD</span>
</div>

{{-- Tenant cards ──────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-3">
    @foreach ($tenants as $t)
      @php
          $row = $latest->get($t->id);
          [$pLabel, $pCls] = $row ? $row->priorityBadge() : ['Pending', 'bg-slate-100 text-slate-600 border-slate-200'];
          $statusCls = match ($row?->status) {
              'ok'      => 'text-emerald-700',
              'partial' => 'text-amber-700',
              'failed'  => 'text-rose-700',
              'skipped' => 'text-amber-700',
              default   => 'text-slate-500',
          };
          // Surface the list of stale blocks (if any) so the operator
          // can see at a glance which data is from a prior snapshot.
          $staleBlocks = is_array($row?->kpis_snapshot)
              ? (array) ($row->kpis_snapshot['stale_blocks'] ?? [])
              : [];
          $sc          = $scorecards->get($t->id) ?? [];
          $cardKpis    = is_array($sc['kpis']  ?? null) ? $sc['kpis']  : [];
          $cardRisks   = is_array($sc['risks'] ?? null) ? $sc['risks'] : [];
          $urgentRisks = collect($cardRisks)->where('severity', 'urgent')->count();
          $watchRisks  = collect($cardRisks)->where('severity', 'watch')->count();
      @endphp
      {{-- Card is a <div> not an <a> so the Inventory link below
           can be a real anchor without nesting. The whole card is
           clickable via JS onclick handler — the show link is the
           default, the explicit Inventory link in the footer
           stops propagation to override. --}}
      <div onclick="window.location='/admin/business-insights/{{ $t->id }}';"
           class="panel p-4 hover:shadow-md transition group block cursor-pointer relative">
          <div class="flex items-start justify-between gap-2 mb-2">
              <div class="min-w-0">
                  <div class="font-semibold text-slate-900 truncate">{{ $t->brand ?: $t->name }}</div>
                  <div class="text-[11px] text-slate-500 font-mono">{{ $t->slug }}</div>
              </div>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold {{ $pCls }} flex-shrink-0">
                  {{ $pLabel }}
              </span>
          </div>

          @if ($row && in_array($row->status, ['ok', 'partial'], true))
            {{-- 5-dot KPI status strip — one per headline KPI --}}
            @if (!empty($cardKpis))
              <div class="flex items-center gap-2 mb-2.5 p-2 bg-slate-50/60 rounded-lg border border-slate-100">
                  @foreach ($kpiOrder as $key => $shortLabel)
                      @php
                          $k     = $cardKpis[$key] ?? null;
                          $st    = is_array($k) ? (string) ($k['status'] ?? 'na') : 'na';
                          $dot   = BusinessInsightsScorer::dotClass($st);
                          $val   = is_array($k) ? (string) ($k['value_fmt'] ?? '—') : '—';
                          $title = is_array($k)
                              ? ($k['label'] . ': ' . $val . ' · ' . $k['threshold'])
                              : 'No data';
                      @endphp
                      <div class="flex flex-col items-center gap-0.5 flex-1 min-w-0" title="{{ $title }}">
                          <span class="w-2.5 h-2.5 rounded-full {{ $dot }} shrink-0"></span>
                          <span class="text-[9px] uppercase tracking-wider text-slate-500 font-semibold truncate w-full text-center">{{ $shortLabel }}</span>
                          <span class="text-[10px] text-slate-700 font-mono truncate w-full text-center">{{ $val }}</span>
                      </div>
                  @endforeach
              </div>
            @endif

            <p class="text-[12px] text-slate-700 leading-relaxed mb-2">{{ \Illuminate\Support\Str::limit($row->summary, 180) }}</p>

            {{-- Risk badges --}}
            @if ($urgentRisks > 0 || $watchRisks > 0)
              <div class="flex items-center gap-1.5 mb-2 flex-wrap">
                  @if ($urgentRisks > 0)
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full border border-rose-200 bg-rose-50 text-rose-700 text-[10px] font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> {{ $urgentRisks }} urgent risk{{ $urgentRisks > 1 ? 's' : '' }}
                    </span>
                  @endif
                  @if ($watchRisks > 0)
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full border border-amber-200 bg-amber-50 text-amber-700 text-[10px] font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> {{ $watchRisks }} watch
                    </span>
                  @endif
              </div>
            @endif

            <div class="flex items-center justify-between text-[10px] text-slate-500">
                <span>
                    {{ $row->generated_at->diffForHumans() }} · {{ $row->model }}
                    @if ($row->status === 'partial')
                      <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full border border-amber-200 bg-amber-50 text-amber-700 text-[9px] font-semibold uppercase tracking-wider" title="Stale blocks: {{ implode(', ', $staleBlocks) }}">
                          Partial · {{ count($staleBlocks) }} stale
                      </span>
                    @endif
                </span>
                <span class="group-hover:text-indigo-600 transition">View →</span>
            </div>
          @elseif ($row)
            <p class="text-[12px] text-slate-700 leading-relaxed mb-3">{{ \Illuminate\Support\Str::limit($row->summary ?? 'No summary.', 220) }}</p>
            <div class="flex items-center justify-between text-[10px]">
                <span class="{{ $statusCls }} font-semibold uppercase tracking-wider">{{ $row->status }}</span>
                <span class="text-slate-400">{{ $row->generated_at?->diffForHumans() }}</span>
            </div>
          @else
            <p class="text-[12px] text-slate-500 italic mb-3">No insight generated yet for this tenant.</p>
            <div class="text-[10px] text-slate-400">Will run at next 06:00 schedule, or click <b>Regenerate all now</b> above.</div>
          @endif

          {{-- Data Inventory link — real <a> with stopPropagation so
               clicking it goes to /inventory, not to the parent
               card's show route. --}}
          <div class="mt-3 pt-3 border-t border-stone-200 flex items-center justify-between">
              <span class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">Reports</span>
              <a href="/admin/business-insights/{{ $t->id }}/inventory"
                 onclick="event.stopPropagation();"
                 class="text-[10px] font-semibold text-rose-700 hover:text-rose-900 underline">
                Data inventory →
              </a>
          </div>
      </div>
    @endforeach
</div>
@endsection
