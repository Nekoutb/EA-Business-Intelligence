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

    {{-- ── Modules ─────────────────────────────────────────────────── --}}
    <div class="panel p-5 lg:col-span-2">
        <h2 class="text-sm font-semibold text-slate-900 mb-1">Modules</h2>
        <p class="text-xs text-slate-500 mb-4">Toggle availability for this workspace. Core modules cannot be disabled.</p>

        <div class="space-y-1.5">
            @foreach ($modules->groupBy('group') as $group => $items)
                <div class="text-[10px] uppercase tracking-wider text-slate-400 mt-3 mb-1">{{ $group }}</div>
                @foreach ($items as $m)
                    <div class="flex items-center justify-between gap-3 py-1.5 border-b border-slate-50">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-slate-800">{{ $m['label'] }}</span>
                            @if (!empty($m['core']))
                                <span class="text-[9px] text-amber-600 font-semibold">CORE</span>
                            @endif
                            <span class="text-[10px] text-slate-400 font-mono">{{ $m['key'] }}</span>
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
                @endforeach
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
