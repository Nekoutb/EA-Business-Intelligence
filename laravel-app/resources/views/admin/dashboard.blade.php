@extends('admin.layout')
@section('title', 'Dashboard')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Platform Dashboard</h1>
    <p class="text-xs text-slate-500 mt-1">Tenant health, module usage and recent activity.</p>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
    @foreach ([
        ['Tenants total',     $totalTenants,     'from-indigo-500 to-blue-500'],
        ['Active',            $activeTenants,    'from-emerald-500 to-teal-500'],
        ['Suspended',         $suspendedTenants, 'from-rose-500 to-orange-500'],
        ['Tenant users',      $tenantUsers,      'from-violet-500 to-fuchsia-500'],
        ['Super admins',      $superAdmins,      'from-amber-500 to-yellow-500'],
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
