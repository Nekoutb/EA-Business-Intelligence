@extends('admin.layout')
@section('title', $tenant->name)

@section('header')
    <div class="flex items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="/admin/tenants" class="text-xs text-slate-500 hover:text-slate-800">← All tenants</a>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $tenant->name }}</h1>
            <p class="text-xs text-slate-500 mt-1">
                <span class="font-mono">/t/{{ $tenant->slug }}</span>
                @php
                    $tone = match($tenant->status) {
                        'active'    => 'bg-emerald-100 text-emerald-700',
                        'trial'     => 'bg-sky-100 text-sky-700',
                        'suspended' => 'bg-rose-100 text-rose-700',
                        default     => 'bg-slate-100 text-slate-700',
                    };
                @endphp
                · <span class="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 {{ $tone }}">{{ $tenant->status }}</span>
                · plan: {{ $tenant->plan }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/tenants/{{ $tenant->id }}/settings"
               class="text-xs font-medium text-indigo-600 hover:text-indigo-800 px-3 py-2 rounded-lg border border-indigo-200">
                Settings →
            </a>
            @if ($tenant->status === 'suspended')
                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/activate" class="inline">@csrf
                    <button class="text-xs font-medium bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg">Reactivate</button>
                </form>
            @else
                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/suspend" class="inline" onsubmit="return confirm('Suspend this tenant? Users will be locked out.')">@csrf
                    <button class="text-xs font-medium bg-rose-600 hover:bg-rose-700 text-white px-3 py-2 rounded-lg">Suspend</button>
                </form>
            @endif
        </div>
    </div>
@endsection

@section('content')
<div class="grid lg:grid-cols-3 gap-4">

    {{-- ── Services & Modules ──────────────────────────────────────── --}}
    <div class="panel p-5 lg:col-span-2">
        <h2 class="text-sm font-semibold text-slate-900 mb-1">Services & Modules</h2>
        <p class="text-xs text-slate-500 mb-4">
            Toggle entire <strong>services</strong> on/off, or fine-tune individual modules.
            Disabling a service hides every module it owns. Core modules can never be disabled.
        </p>

        <div class="space-y-5">
            @foreach ($services as $service)
                @php
                    $serviceOn = (bool) $service['enabled'];
                    $sModules  = $modules->where('service', $service['key'])->values();
                    $serviceSoon = ($service['status'] ?? 'live') === 'coming_soon';
                @endphp
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    {{-- Service header + master toggle --}}
                    <div class="flex items-center justify-between gap-3 px-4 py-3 bg-slate-50 border-b border-slate-200">
                        <div class="flex items-center gap-2 min-w-0">
                            <svg class="w-4 h-4 text-slate-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $service['icon'] }}"/></svg>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-900 truncate">{{ $service['label'] }}</span>
                                    @if ($serviceSoon)
                                        <span class="text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-amber-100 text-amber-700">Coming soon</span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-slate-500 truncate">{{ $service['description'] ?? '' }}</div>
                            </div>
                        </div>
                        <form method="POST" action="/admin/tenants/{{ $tenant->id }}/services/{{ $service['key'] }}/toggle">
                            @csrf
                            <button type="submit"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition
                                           {{ $serviceOn ? 'bg-emerald-500' : 'bg-slate-300' }}">
                                <span class="inline-block h-4 w-4 transform bg-white rounded-full shadow transition
                                             {{ $serviceOn ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </button>
                        </form>
                    </div>

                    {{-- Module rows --}}
                    <div class="divide-y divide-slate-100 {{ $serviceOn ? '' : 'opacity-50' }}">
                        @forelse ($sModules as $m)
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <div class="flex items-center gap-2 min-w-0">
                                    <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $m['icon'] }}"/></svg>
                                    <span class="text-sm text-slate-800 truncate">{{ $m['label'] }}</span>
                                    @if (!empty($m['core']))
                                        <span class="text-[9px] text-amber-600 font-semibold">CORE</span>
                                    @endif
                                    @if (($m['status'] ?? 'live') === 'coming_soon')
                                        <span class="text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-amber-100 text-amber-700">soon</span>
                                    @endif
                                    <span class="text-[10px] text-slate-400 font-mono ml-auto">{{ $m['key'] }}</span>
                                </div>
                                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/modules/{{ $m['key'] }}/toggle">
                                    @csrf
                                    @php $isOn = (bool) $m['enabled']; @endphp
                                    <button type="submit"
                                            @disabled(!empty($m['core']))
                                            class="relative inline-flex h-6 w-11 items-center rounded-full transition
                                                   {{ $isOn ? 'bg-emerald-500' : 'bg-slate-300' }}
                                                   {{ !empty($m['core']) ? 'opacity-50 cursor-not-allowed' : '' }}">
                                        <span class="inline-block h-4 w-4 transform bg-white rounded-full shadow transition
                                                     {{ $isOn ? 'translate-x-6' : 'translate-x-1' }}"></span>
                                    </button>
                                </form>
                            </div>
                        @empty
                            <div class="px-4 py-3 text-xs text-slate-400 italic">No modules in this service yet.</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Sidebar: users + audit ─────────────────────────────────── --}}
    <div class="space-y-4">
        <div class="panel p-5">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">Users ({{ $users->count() }})</h2>
            <div class="space-y-2 text-sm">
                @forelse ($users as $u)
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-slate-800 truncate">{{ $u->name }}</div>
                            <div class="text-[11px] text-slate-500 truncate">{{ $u->email }}</div>
                        </div>
                        <span class="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5
                                     bg-slate-100 text-slate-600">{{ $u->role }}</span>
                    </div>
                @empty
                    <p class="text-xs text-slate-400 italic">No users yet.</p>
                @endforelse
            </div>
        </div>

        <div class="panel p-5">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">Recent Activity</h2>
            <div class="space-y-2.5 text-xs max-h-80 overflow-y-auto">
                @forelse ($audit as $log)
                    <div class="border-l-2 border-slate-200 pl-3">
                        <div class="font-medium text-slate-800">{{ $log->action }}</div>
                        <div class="text-slate-500">{{ $log->target ?? '—' }}</div>
                        <div class="text-[10px] text-slate-400 mt-0.5">{{ $log->created_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <p class="text-slate-400 italic">No activity yet.</p>
                @endforelse
            </div>
        </div>
    </div>

</div>
@endsection
