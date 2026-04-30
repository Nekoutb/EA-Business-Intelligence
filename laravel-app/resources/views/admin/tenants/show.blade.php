@extends('admin.layout')
@section('title', $tenant->name)

@section('header')
    <div class="flex items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="/admin/tenants" class="text-xs text-slate-500 hover:text-slate-800">← All tenants</a>
            </div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-slate-900">{{ $tenant->name }}</h1>
                <button type="button"
                        onclick="document.getElementById('ea-rename-panel').classList.toggle('hidden')"
                        class="text-[11px] font-medium text-slate-500 hover:text-slate-900 inline-flex items-center gap-1 px-2 py-1 rounded border border-slate-200 hover:border-slate-400 transition"
                        title="Rename tenant">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Rename
                </button>
            </div>
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
            {{-- Odoo schema probe — diagnostic JSON of the tenant's Odoo
                 schema. One-click access so the URL never has to be
                 hand-constructed (curly-brace placeholders trip people
                 up). Opens in a new tab so the JSON page doesn't replace
                 the tenant detail view. --}}
            <a href="/admin/tenants/{{ $tenant->id }}/odoo-probe"
               target="_blank" rel="noopener"
               class="text-xs font-medium text-slate-600 hover:text-slate-900 px-3 py-2 rounded-lg border border-slate-300 inline-flex items-center gap-1.5"
               title="Run the Odoo schema probe — opens a JSON page in a new tab. Read-only diagnostic.">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65" stroke-linecap="round"/></svg>
                Odoo probe
            </a>
            {{-- Vehicle scan analyzer — runs four candidate filter
                 strategies (literal scanned, state+writedate,
                 state+createdate, any-active+writedate) for a chosen
                 period and returns a side-by-side count comparison so
                 we can see which definition matches the customer's
                 expectation. Defaults to current month. --}}
            <a href="/admin/tenants/{{ $tenant->id }}/vehicle-scan-analyzer?period=current_month"
               target="_blank" rel="noopener"
               class="text-xs font-medium text-slate-600 hover:text-slate-900 px-3 py-2 rounded-lg border border-slate-300 inline-flex items-center gap-1.5"
               title="Compare four candidate filter strategies for vehicle scan counts (current month) — opens a JSON page in a new tab. Read-only diagnostic.">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Vehicle scan analyzer
            </a>
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

    {{-- ─────────── Rename panel (toggled by the Rename button) ─────────── --}}
    <div id="ea-rename-panel" class="hidden mt-4 bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <form method="POST" action="/admin/tenants/{{ $tenant->id }}/rename" class="space-y-4">
            @csrf
            <div class="flex items-baseline justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Rename tenant</h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">
                        Updates the display name, brand and plan. The URL slug
                        <code class="bg-slate-100 px-1 py-0.5 rounded">/t/{{ $tenant->slug }}</code>
                        is immutable to keep bookmarks &amp; audit history consistent.
                    </p>
                </div>
                <button type="button" onclick="document.getElementById('ea-rename-panel').classList.add('hidden')"
                        class="text-[11px] text-slate-500 hover:text-slate-800">Close ✕</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[11px] font-medium text-slate-600 mb-1">Display name <span class="text-rose-500">*</span></label>
                    <input name="name" required maxlength="191"
                           value="{{ old('name', $tenant->name) }}"
                           placeholder="Kissywears"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <p class="text-[10px] text-slate-400 mt-1">Shown in the admin and on the tenant detail page.</p>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-slate-600 mb-1">Brand</label>
                    <input name="brand" maxlength="191"
                           value="{{ old('brand', $tenant->brand) }}"
                           placeholder="Kissywears"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <p class="text-[10px] text-slate-400 mt-1">Shown to tenant users in the sidebar &amp; login page (synced to <code class="bg-slate-100 px-1 rounded">APP_BRAND</code>).</p>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-slate-600 mb-1">Plan</label>
                    <input name="plan" maxlength="32"
                           value="{{ old('plan', $tenant->plan) }}"
                           placeholder="standard"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
            </div>
            @if ($errors->any())
                <div class="text-xs text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-3">
                    @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('ea-rename-panel').classList.add('hidden')"
                        class="text-xs font-medium text-slate-600 hover:text-slate-900 px-3 py-2">
                    Cancel
                </button>
                <button type="submit"
                        class="text-xs font-semibold bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white px-4 py-2 rounded-lg shadow transition">
                    Save changes
                </button>
            </div>
        </form>
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

        {{-- ── Dashboard KPIs (toggle individual cards on /Dashboard) ─ --}}
        @php
            $kpiSections   = config('dashboard_kpis.sections', []);
            $kpiRegistry   = config('dashboard_kpis.kpis', []);
            $tenantKpiRows = $tenant->dashboardKpis()
                ->get(['kpi_key', 'enabled'])
                ->mapWithKeys(fn ($r) => [$r->kpi_key => (bool) $r->enabled])
                ->all();
        @endphp
        <div class="mt-6 panel">
            <div class="px-5 pt-5 pb-3">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900">KPI &amp; section toggles</h2>
                    <span class="text-[10px] uppercase tracking-widest text-slate-500 font-medium">
                        Business Intelligence · Dashboard &amp; Revenue
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    Toggle individual KPI cards or table sections for this tenant. A single switch affects both the Dashboard and the Revenue Analysis page when the same KPI appears on both. Disabled items are hidden from the tenant view but the underlying data calculation continues to run.
                </p>
            </div>

            @foreach ($kpiSections as $sectionKey => $sectionMeta)
                <div class="border-t border-slate-100">
                    <div class="px-5 py-2.5 bg-slate-50/60 border-b border-slate-100 flex items-baseline justify-between">
                        <div class="text-[11px] font-semibold uppercase tracking-widest text-slate-700">{{ $sectionMeta['label'] ?? $sectionKey }}</div>
                        <div class="text-[10px] text-slate-500">{{ $sectionMeta['description'] ?? '' }}</div>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @foreach (collect($kpiRegistry)->where('section', $sectionKey) as $kpi)
                            @php
                                $key      = $kpi['key'];
                                $enabled  = $tenantKpiRows[$key] ?? (bool) ($kpi['default'] ?? true);
                            @endphp
                            <div class="px-5 py-3 flex items-center gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-[13px] font-medium text-slate-900">{{ $kpi['label'] }}</div>
                                    <div class="text-[11px] text-slate-500 truncate">{{ $kpi['description'] ?? '' }}</div>
                                </div>
                                <code class="text-[10px] font-mono bg-slate-100 text-slate-600 rounded px-1.5 py-0.5">{{ $key }}</code>
                                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/dashboard-kpis/{{ $key }}/toggle" class="shrink-0">
                                    @csrf
                                    <button type="submit"
                                            title="{{ $enabled ? 'Disable this KPI for this tenant' : 'Enable this KPI for this tenant' }}"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition {{ $enabled ? 'bg-emerald-500' : 'bg-slate-300' }}">
                                        <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-4' : 'translate-x-1' }}"></span>
                                    </button>
                                </form>
                            </div>
                        @endforeach
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
