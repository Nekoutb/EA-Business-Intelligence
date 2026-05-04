@extends('admin.layout')
@section('title', 'Dashboard')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Platform Dashboard</h1>
    <p class="text-xs text-slate-500 mt-1">Tenant health, module usage and recent activity.</p>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
    @foreach ([
        ['Tenants total',     $totalTenants,     'from-indigo-500 to-blue-500',     '/admin/tenants'],
        ['Active',            $activeTenants,    'from-emerald-500 to-teal-500',    '/admin/tenants?status=active'],
        ['Suspended',         $suspendedTenants, 'from-rose-500 to-orange-500',     '/admin/tenants?status=suspended'],
        ['Tenant users',      $tenantUsers,      'from-violet-500 to-fuchsia-500',  '/admin/users'],
        ['Super admins',      $superAdmins,      'from-amber-500 to-yellow-500',    '/admin/users?tenant=platform'],
    ] as [$label, $value, $grad, $href])
      <a href="{{ $href }}" class="panel p-4 hover:shadow-md transition group">
          <div class="flex items-center gap-3">
              <div class="w-1 h-10 rounded bg-gradient-to-b {{ $grad }}"></div>
              <div>
                  <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium">{{ $label }}</p>
                  <div class="text-xl font-bold text-slate-900 group-hover:text-indigo-700 transition">{{ number_format($value) }}</div>
              </div>
          </div>
      </a>
    @endforeach
</div>

{{-- ── Business Insights section ─────────────────────────────────
     Daily Claude-generated narrative for each tenant. Used by the
     super admin to prep client meetings; refreshed automatically
     at 06:00 by the schedule:run cron.                         --}}
<div class="panel p-5 mb-6">
    <div class="flex items-end justify-between gap-3 mb-4 flex-wrap">
        <div>
            <h2 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                <span>Business Insights</span>
                <span class="text-[9px] font-bold uppercase tracking-wider text-indigo-600 bg-indigo-50 border border-indigo-200 rounded px-1.5 py-0.5">AI · Claude</span>
            </h2>
            <p class="text-[11px] text-slate-500 mt-1">
                Daily narrative summary per tenant — refreshed automatically at <b>06:00</b>. Click any card for the full advisory write-up.
                @if (isset($insightCounts))
                  <span class="ml-2">
                      <span class="text-rose-700 font-semibold">{{ $insightCounts['urgent'] }} urgent</span> ·
                      <span class="text-amber-700 font-semibold">{{ $insightCounts['watch'] }} watch</span> ·
                      <span class="text-emerald-700 font-semibold">{{ $insightCounts['good'] }} good</span>
                  </span>
                @endif
            </p>
        </div>
        <a href="/admin/business-insights" class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-900 border border-indigo-200 hover:border-indigo-400 rounded-lg px-3 py-1.5">
            View all →
        </a>
    </div>

    @if (!empty($featuredInsights) && count($featuredInsights))
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
          @foreach ($featuredInsights as $row)
            @php
                [$pLabel, $pCls] = $row->priorityBadge();
                $tenant = $row->tenant;
            @endphp
            <a href="/admin/business-insights/{{ $row->tenant_id }}" class="border border-slate-200 rounded-lg p-3 hover:bg-slate-50 transition group block">
                <div class="flex items-start justify-between gap-2 mb-1">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-900 text-[13px] truncate">{{ $tenant?->brand ?: $tenant?->name ?: 'Tenant #' . $row->tenant_id }}</div>
                        <div class="text-[10px] text-slate-500 font-mono">{{ $tenant?->slug }}</div>
                    </div>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full border text-[9px] font-semibold {{ $pCls }} flex-shrink-0">{{ $pLabel }}</span>
                </div>
                <p class="text-[11.5px] text-slate-700 leading-relaxed line-clamp-3">{{ $row->summary }}</p>
                <div class="mt-2 flex items-center justify-between text-[10px] text-slate-400">
                    <span>{{ $row->generated_at?->diffForHumans() }}</span>
                    <span class="group-hover:text-indigo-600 transition">Open →</span>
                </div>
            </a>
          @endforeach
      </div>
    @else
      <div class="text-center text-[12px] text-slate-500 py-6">
          No insights generated yet. Run <code class="text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded font-mono">php artisan insights:generate-daily</code> on the server, or wait for the 06:00 schedule.
          <a href="/admin/business-insights" class="ml-2 text-indigo-600 underline">Manage</a>
      </div>
    @endif
</div>

<div class="grid lg:grid-cols-3 gap-4">
    <div class="panel p-5 lg:col-span-2">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Module Activation</h2>
        <div class="space-y-2.5">
            @foreach ($moduleStats as $m)
                <div class="flex items-center gap-3">
                    <div class="w-32 shrink-0 text-xs text-slate-700 truncate">{{ $m['label'] }}@if($m['core'])<span class="ml-1 text-[9px] text-amber-600 font-semibold">CORE</span>@endif</div>
                    <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-indigo-500 to-blue-500" style="width: {{ $m['pct'] }}%"></div>
                    </div>
                    <div class="w-20 text-right text-[11px] text-slate-500 tabular-nums">{{ $m['count'] }} / {{ $totalTenants }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Recent Activity</h2>
        <div class="space-y-3 text-xs max-h-96 overflow-y-auto">
            @forelse ($recentAudit as $log)
                <div class="border-l-2 border-slate-200 pl-3">
                    <div class="font-medium text-slate-800">{{ $log->action }}</div>
                    <div class="text-slate-500">
                        {{ $log->target ?? '—' }}
                        @if ($log->tenant)
                            · <span class="text-slate-400">{{ $log->tenant->slug }}</span>
                        @endif
                    </div>
                    <div class="text-[10px] text-slate-400 mt-0.5">{{ $log->created_at?->diffForHumans() }}</div>
                </div>
            @empty
                <p class="text-slate-400 italic">No activity yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
