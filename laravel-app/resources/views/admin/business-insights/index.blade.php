@extends('admin.layout')
@section('title', 'Business Insights')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Business Insights</h1>
            <p class="text-xs text-slate-500 mt-1">
                Daily Claude-generated narrative summary for each tenant — refreshed automatically at 06:00.
                Click into any tenant for the full advisory write-up to share in client meetings.
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
  $totalOk = $latest->where('status', 'ok')->count();
  $totalUrgent = $latest->where('priority', 'urgent')->count();
  $totalWatch = $latest->where('priority', 'watch')->count();
  $totalGood = $latest->where('priority', 'good')->count();
  $totalFailed = $latest->where('status', 'failed')->count();
  $totalSkipped = $latest->where('status', 'skipped')->count();
@endphp

{{-- Summary band ─────────────────────────────────────────── --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
    @foreach ([
        ['Tenants',  count($tenants),  'from-slate-500 to-slate-700'],
        ['Urgent',   $totalUrgent,     'from-rose-500 to-orange-500'],
        ['Watch',    $totalWatch,      'from-amber-500 to-yellow-500'],
        ['Good',     $totalGood,       'from-emerald-500 to-teal-500'],
        ['Pending',  $totalSkipped + $totalFailed + (count($tenants) - $latest->count()), 'from-violet-500 to-indigo-500'],
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
      @endphp
      <a href="/admin/business-insights/{{ $t->id }}" class="panel p-4 hover:shadow-md transition group block">
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
            <p class="text-[12px] text-slate-700 leading-relaxed mb-3">{{ \Illuminate\Support\Str::limit($row->summary, 220) }}</p>
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
      </a>
    @endforeach
</div>
@endsection
