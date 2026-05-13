@extends('admin.layout')
@section('title', 'Business Insight · ' . ($tenant->brand ?: $tenant->name))

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-medium">Business Insight</div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $tenant->brand ?: $tenant->name }}</h1>
            <p class="text-xs text-slate-500 mt-1">
                <span class="font-mono">{{ $tenant->slug }}</span> ·
                @if ($latest)
                  {{ $latest->generated_at->diffForHumans() }} · model {{ $latest->model }}
                  · tokens in:{{ number_format($latest->input_tokens) }} / out:{{ number_format($latest->output_tokens) }}
                @else
                  no insight generated yet
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/business-insights" class="text-[12px] font-medium text-slate-600 border border-slate-200 hover:border-slate-400 rounded-lg px-3 py-2">← All tenants</a>
            <form method="POST" action="/admin/business-insights/{{ $tenant->id }}/refresh" onsubmit="return confirm('Regenerate now? This will call the LLM.')">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 rounded-lg px-3.5 py-2 shadow transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Refresh now
                </button>
            </form>
            <button onclick="window.print()" class="text-[12px] font-medium text-slate-600 border border-slate-200 hover:border-slate-400 rounded-lg px-3 py-2 print:hidden">
                Print
            </button>
        </div>
    </div>
@endsection

@section('content')
@if (!$latest)
  <div class="panel p-6 text-center text-slate-500">
      No insight generated yet for this tenant. Click <b>Refresh now</b> to generate the first one, or wait for the 06:00 schedule.
  </div>
@else
  @php
      [$pLabel, $pCls] = $latest->priorityBadge();
  @endphp

  {{-- Top callout — priority + summary, suitable for the meeting opener --}}
  <div class="panel p-5 mb-4 border-l-4
       {{ $latest->priority === 'urgent' ? 'border-rose-500'
        : ($latest->priority === 'watch' ? 'border-amber-500' : 'border-emerald-500') }}">
      <div class="flex items-start gap-3">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold {{ $pCls }} flex-shrink-0 mt-0.5">
              {{ $pLabel }}
          </span>
          <p class="text-[14px] text-slate-800 leading-relaxed font-medium">{{ $latest->summary }}</p>
      </div>
      @if ($latest->status === 'failed')
        <div class="mt-3 text-[11px] text-rose-700 bg-rose-50 border border-rose-200 rounded p-2">
            <b>Failure:</b> {{ $latest->error_msg ?? 'unknown error' }}
        </div>
      @elseif ($latest->status === 'skipped')
        <div class="mt-3 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            <b>Skipped:</b> {{ $latest->error_msg ?? 'no Odoo data available — sync first' }}
        </div>
      @elseif ($latest->status === 'partial')
        @php
            $staleBlocks = is_array($latest->kpis_snapshot)
                ? (array) ($latest->kpis_snapshot['stale_blocks'] ?? [])
                : [];
        @endphp
        <div class="mt-3 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            <b>Partial data:</b> today's live fetch failed for
            <code class="font-mono text-[10px] bg-white px-1 rounded">{{ implode(', ', $staleBlocks) ?: 'one or more blocks' }}</code>.
            Those figures are from the prior snapshot — directional only.
            @if ($latest->error_msg)
              <span class="block mt-1 text-amber-600">{{ $latest->error_msg }}</span>
            @endif
        </div>
      @endif
  </div>

  {{-- Main markdown body — formatted for client meetings --}}
  @if ($latest->insight_md)
    <div class="panel p-6 mb-4">
        <article class="prose prose-slate max-w-none prose-headings:text-slate-900 prose-h2:text-base prose-h2:mt-5 prose-h2:mb-2 prose-p:text-[13px] prose-p:leading-relaxed prose-li:text-[13px] prose-li:leading-relaxed prose-strong:text-slate-900 prose-strong:font-semibold prose-ol:my-2 prose-ul:my-2">
            {!! \Illuminate\Support\Str::markdown($latest->insight_md) !!}
        </article>
    </div>
  @endif

  {{-- Footer: KPI snapshot (collapsed) + history --}}
  <div class="grid lg:grid-cols-2 gap-4 print:hidden">
      <details class="panel p-4">
          <summary class="cursor-pointer font-semibold text-slate-900 text-sm">KPI snapshot used by Claude</summary>
          <pre class="text-[11px] text-slate-700 mt-3 overflow-x-auto bg-slate-50 p-3 rounded font-mono">{{ json_encode($latest->kpis_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
      </details>

      <div class="panel p-4">
          <h3 class="font-semibold text-slate-900 text-sm mb-3">Generation history (last 14)</h3>
          <div class="space-y-2 max-h-72 overflow-y-auto">
              @foreach ($history as $h)
                @php [$lbl, $cls] = $h->priorityBadge(); @endphp
                <div class="flex items-center gap-2 text-[11px] {{ $h->id === $latest->id ? 'bg-indigo-50/40 -mx-2 px-2 py-1 rounded' : '' }}">
                    <span class="font-mono text-slate-500 w-32 flex-shrink-0">{{ $h->generated_at->format('Y-m-d H:i') }}</span>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full border text-[9px] font-semibold {{ $cls }}">{{ $lbl }}</span>
                    <span class="text-slate-600 truncate">{{ $h->status === 'ok' ? \Illuminate\Support\Str::limit($h->summary, 60) : $h->status }}</span>
                </div>
              @endforeach
          </div>
      </div>
  </div>
@endif

@push('styles')
<style>
  /* Print-friendly: drop the dark sidebar + sticky chrome so the
     advisory body fills the page when the admin prints to PDF for
     a client meeting. */
  @media print {
    aside, .print\:hidden, header { display: none !important; }
    body { background: white !important; color: black !important; }
    .panel { box-shadow: none !important; border: 1px solid #e5e7eb; }
  }
</style>
@endpush
@endsection
