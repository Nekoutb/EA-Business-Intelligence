@extends('admin.layout')
@section('title', 'Business Insight · ' . ($tenant->brand ?: $tenant->name))

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-medium">Management Report · Business Insight</div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $tenant->brand ?: $tenant->name }}</h1>
            <p class="text-xs text-slate-500 mt-1">
                <span class="font-mono">{{ $tenant->slug }}</span> ·
                @if ($latest)
                  Period as of <strong>{{ $latest->generated_at->format('d M Y') }}</strong> ·
                  Generated {{ $latest->generated_at->diffForHumans() }} · model {{ $latest->model }}
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
      $scorecard = $latest->scorecard();
      $headlineKpis  = is_array($scorecard['kpis'] ?? null)    ? $scorecard['kpis']    : [];
      $varianceRows  = is_array($scorecard['variance_table'] ?? null) ? $scorecard['variance_table'] : [];
      $risks         = is_array($scorecard['risks'] ?? null)   ? $scorecard['risks']   : [];
      $currency      = (string) ($scorecard['currency'] ?? ($latest->kpis_snapshot['currency'] ?? ''));
      $overallStatus = (string) ($scorecard['overall'] ?? 'na');
      $overallBorder = match ($overallStatus) {
          'red'   => 'border-rose-500',
          'amber' => 'border-amber-500',
          'green' => 'border-emerald-500',
          default => 'border-slate-300',
      };
      // Convenience accessors for the KPI strip below — keep order
      // explicit so the 5 cards always render the same KPIs in the
      // same slots even when one is unavailable.
      $kpiOrder = ['revenue_yoy', 'gross_margin', 'ebitda_margin', 'dso', 'overdue_rate'];
  @endphp

  {{-- ─── 1. Executive Summary callout ─── --}}
  <div class="panel p-5 mb-4 border-l-4 {{ $overallBorder }}">
      <div class="flex items-start gap-3">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold {{ $pCls }} flex-shrink-0 mt-0.5">
              {{ $pLabel }}
          </span>
          <div>
              <p class="text-[14px] text-slate-800 leading-relaxed font-medium">{{ $latest->summary }}</p>
              @if ($currency)
                <p class="text-[10px] text-slate-500 mt-1.5 uppercase tracking-wider">Currency: <span class="font-mono font-semibold">{{ $currency }}</span></p>
              @endif
          </div>
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

  {{-- ─── 2. Headline KPI strip — 5 cards, traffic-light coded ─── --}}
  @if (!empty($headlineKpis))
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
        @foreach ($kpiOrder as $key)
            @php
                $k = $headlineKpis[$key] ?? null;
                if (!is_array($k)) continue;
                $status = (string) ($k['status'] ?? 'na');
                $cls    = \App\Services\BusinessInsightsScorer::statusClasses($status);
                $shade  = match ($status) {
                    'red'   => 'bg-rose-50/60 border-rose-200',
                    'amber' => 'bg-amber-50/60 border-amber-200',
                    'green' => 'bg-emerald-50/60 border-emerald-200',
                    default => 'bg-slate-50/60 border-slate-200',
                };
                $dot    = \App\Services\BusinessInsightsScorer::dotClass($status);
            @endphp
            <div class="rounded-xl border {{ $shade }} p-3.5">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold">{{ $k['label'] }}</span>
                    <span class="w-2 h-2 rounded-full {{ $dot }} shrink-0" title="{{ $status }}"></span>
                </div>
                <div class="text-xl font-bold text-slate-900">{{ $k['value_fmt'] }}</div>
                @if (!empty($k['prior_fmt']) && $k['prior_fmt'] !== '—')
                  <div class="text-[10px] text-slate-500 mt-0.5">{{ $k['prior_fmt'] }}</div>
                @endif
                <div class="text-[9.5px] text-slate-500 leading-snug mt-1.5 border-t border-slate-200/60 pt-1.5">{{ $k['threshold'] }}</div>
            </div>
        @endforeach
    </div>
  @endif

  {{-- ─── 3. Financial Performance variance table ─── --}}
  @if (!empty($varianceRows))
    <div class="panel p-5 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Financial performance — variance analysis</h2>
            <span class="text-[10px] uppercase tracking-wider text-slate-500">Actual · Prior · Δ · Δ%</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead class="text-[10px] uppercase tracking-wider text-slate-500">
                    <tr class="border-b border-slate-200">
                        <th class="text-left py-2 pr-3 font-semibold">Metric</th>
                        <th class="text-right py-2 px-3 font-semibold">Current</th>
                        <th class="text-right py-2 px-3 font-semibold">Prior</th>
                        <th class="text-right py-2 px-3 font-semibold">Variance</th>
                        <th class="text-right py-2 px-3 font-semibold">Δ %</th>
                        <th class="text-center py-2 pl-3 font-semibold w-12">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($varianceRows as $row)
                        @php
                            $rs   = (string) ($row['status'] ?? 'na');
                            $rdot = \App\Services\BusinessInsightsScorer::dotClass($rs);
                            $bg   = match ($rs) {
                                'red'   => 'bg-rose-50/40',
                                'amber' => 'bg-amber-50/40',
                                'green' => 'bg-emerald-50/40',
                                default => '',
                            };
                        @endphp
                        <tr class="{{ $bg }} hover:bg-slate-50/60">
                            <td class="py-2 pr-3 text-slate-700">{{ $row['label'] }}</td>
                            <td class="py-2 px-3 text-right font-mono font-semibold text-slate-900">{{ $row['current_fmt'] }}</td>
                            <td class="py-2 px-3 text-right font-mono text-slate-500">{{ $row['prior_fmt'] }}</td>
                            <td class="py-2 px-3 text-right font-mono text-slate-700">{{ $row['variance_fmt'] }}</td>
                            <td class="py-2 px-3 text-right font-mono
                                       {{ str_starts_with((string) $row['variance_pct_fmt'], '+') ? 'text-emerald-700' :
                                          (str_starts_with((string) $row['variance_pct_fmt'], '-') ? 'text-rose-700' : 'text-slate-500') }}">
                                {{ $row['variance_pct_fmt'] }}
                            </td>
                            <td class="py-2 pl-3 text-center">
                                <span class="inline-block w-2.5 h-2.5 rounded-full {{ $rdot }}" title="{{ $rs }}"></span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[10px] text-slate-500 mt-3">
            Variance status: green ≥ +5% favourable · amber within ±5% · red ≥ 5% adverse. Direction is favourable-better — for cost-side metrics (CpO, Opex) higher = adverse.
        </p>
    </div>
  @endif

  {{-- ─── 4. Risk Register ─── --}}
  <div class="panel p-5 mb-4">
      <div class="flex items-center justify-between mb-3">
          <h2 class="text-sm font-semibold text-slate-900">Risk register</h2>
          @if (!empty($risks))
              @php
                  $urgentCount = collect($risks)->where('severity', 'urgent')->count();
                  $watchCount  = collect($risks)->where('severity', 'watch')->count();
              @endphp
              <div class="flex items-center gap-1.5 text-[10px]">
                  @if ($urgentCount > 0)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-50 border border-rose-200 text-rose-700 font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> {{ $urgentCount }} urgent
                    </span>
                  @endif
                  @if ($watchCount > 0)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> {{ $watchCount }} watch
                    </span>
                  @endif
              </div>
          @endif
      </div>
      @if (empty($risks))
        <p class="text-[12px] text-slate-500 italic">No risks above threshold — all headline KPIs within tolerance.</p>
      @else
        <div class="space-y-2">
            @foreach ($risks as $r)
                @php
                    $sev = (string) ($r['severity'] ?? 'watch');
                    [$sevCls, $sevDot] = $sev === 'urgent'
                        ? ['bg-rose-50 border-rose-200',  'bg-rose-500']
                        : ['bg-amber-50 border-amber-200', 'bg-amber-500'];
                @endphp
                <div class="border {{ $sevCls }} rounded-lg p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="w-2 h-2 rounded-full {{ $sevDot }} shrink-0"></span>
                            <span class="text-[13px] font-semibold text-slate-900">{{ $r['label'] }}</span>
                            <span class="font-mono text-[11px] text-slate-600 bg-white border border-slate-200 px-1.5 py-0.5 rounded">{{ $r['value_fmt'] }}</span>
                        </div>
                        <span class="text-[9.5px] uppercase tracking-wider font-semibold {{ $sev === 'urgent' ? 'text-rose-700' : 'text-amber-700' }} shrink-0">{{ $sev }}</span>
                    </div>
                    <div class="text-[11px] text-slate-600 mt-1.5 pl-4">{{ $r['recommendation'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1 pl-4 italic">{{ $r['threshold'] }}</div>
                </div>
            @endforeach
        </div>
      @endif
  </div>

  {{-- ─── 5. Main narrative — Executive Summary / Financial / Operational / Outlook / Decisions ─── --}}
  @if ($latest->insight_md)
    <div class="panel p-6 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Advisor narrative</h2>
            <span class="text-[10px] uppercase tracking-wider text-slate-500">Structured per Management Reporting standard</span>
        </div>
        <article class="prose prose-slate max-w-none prose-headings:text-slate-900 prose-h2:text-sm prose-h2:font-semibold prose-h2:uppercase prose-h2:tracking-wider prose-h2:text-slate-700 prose-h2:mt-5 prose-h2:mb-2 prose-h2:border-b prose-h2:border-slate-200 prose-h2:pb-1 prose-p:text-[13px] prose-p:leading-relaxed prose-li:text-[13px] prose-li:leading-relaxed prose-strong:text-slate-900 prose-strong:font-semibold prose-ol:my-2 prose-ul:my-2">
            {!! \Illuminate\Support\Str::markdown($latest->insight_md) !!}
        </article>
    </div>
  @endif

  {{-- ─── 6. Appendix: snapshot + history (collapsed) ─── --}}
  <div class="grid lg:grid-cols-2 gap-4 print:hidden">
      <details class="panel p-4">
          <summary class="cursor-pointer font-semibold text-slate-900 text-sm">Appendix · KPI snapshot used by Claude</summary>
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
