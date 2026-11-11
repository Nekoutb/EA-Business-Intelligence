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
            <a href="/admin/tenants/{{ $tenant->id }}/data-hosting"
               class="text-xs font-medium text-slate-700 hover:text-slate-900 px-3 py-2 rounded-lg border border-slate-300"
               title="Where this client's data lives (EA shared, own database, or the client's server)">
                Data hosting · {{ ['shared' => 'EA shared', 'ea' => 'own DB', 'client' => 'client server'][\App\Tenancy\TenantDatabase::location($tenant)] ?? 'EA shared' }}
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
            {{-- Delete button — toggles the panel below. Kept visually
                 distinct from Suspend / Reactivate so it isn't a muscle-
                 memory misclick. --}}
            <button type="button"
                    onclick="document.getElementById('ea-delete-panel').classList.toggle('hidden')"
                    class="text-xs font-medium text-rose-700 hover:text-rose-900 px-3 py-2 rounded-lg border border-rose-300">
                Delete tenant…
            </button>
        </div>
    </div>

    {{-- ─────────── Delete panel (toggled by the Delete button) ───────────
         Requires the operator to TYPE the slug. Slug-typing makes
         accidents dramatically harder than a single Confirm click —
         copy/paste is the only fast path, which is deliberate.   --}}
    <div id="ea-delete-panel" class="hidden mt-4 bg-rose-50 border-2 border-rose-300 rounded-xl p-5 shadow-sm">
        <div class="flex items-start gap-3 mb-3">
            <svg class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <h3 class="text-sm font-bold text-rose-900">Delete tenant</h3>
                <p class="text-[12px] text-rose-800 mt-1 leading-relaxed">
                    <strong>Suspend</strong> is usually what you want — it locks users out without losing data.
                    Use <strong>Delete</strong> when the tenant is gone for good (off-boarded, demo cleanup,
                    test workspace). Soft-deleted tenants disappear from queries but are recoverable via a DB UPDATE.
                    <strong>Force delete</strong> purges users, modules, services, settings, business insights and audit
                    logs — irreversible.
                </p>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-3 p-3 rounded border border-rose-300 bg-rose-100 text-rose-900 text-[11.5px]">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="/admin/tenants/{{ $tenant->id }}/delete" class="space-y-3">
            @csrf
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-rose-800 font-semibold mb-1">
                    Type the slug <code class="bg-white px-1.5 py-0.5 rounded border border-rose-200 mono text-[11px]">{{ $tenant->slug }}</code> to confirm *
                </label>
                <input type="text" name="confirm_slug" required autocomplete="off"
                       placeholder="{{ $tenant->slug }}"
                       class="w-full md:w-80 border border-rose-300 rounded-lg px-3 py-2 text-sm mono bg-white focus:border-rose-600 focus:outline-none">
            </div>

            <label class="flex items-start gap-2 text-[12px] text-rose-900 cursor-pointer">
                <input type="checkbox" name="force" value="1" class="mt-0.5 rounded border-rose-400 text-rose-700 focus:ring-rose-500">
                <span>
                    <strong>Force delete</strong> — permanently purge all data (irreversible).
                    <span class="block text-[10.5px] text-rose-700">If unchecked, the tenant is soft-deleted (recoverable).</span>
                </span>
            </label>

            <div class="flex items-center gap-2 pt-2">
                <button type="button" onclick="document.getElementById('ea-delete-panel').classList.add('hidden')"
                        class="text-[12px] text-rose-700 hover:text-rose-900 px-3 py-2">Cancel</button>
                <button type="submit"
                        onclick="return confirm('Delete tenant {{ $tenant->slug }}? Confirm slug above must match exactly.')"
                        class="text-[12px] font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded-lg px-4 py-2 shadow">
                    Delete tenant
                </button>
            </div>
        </form>
    </div>

    {{-- ─────────── Rename panel (toggled by the Rename button) ─────────── --}}
    <div id="ea-rename-panel" class="hidden mt-4 bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <form method="POST" action="/admin/tenants/{{ $tenant->id }}/rename" class="space-y-4">
            @csrf
            <div class="flex items-baseline justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Rename tenant</h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">
                        Updates the display name, brand, plan and (carefully) the URL slug
                        <code class="bg-slate-100 px-1 py-0.5 rounded">/t/{{ $tenant->slug }}</code>.
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

            {{-- Slug-edit row: separate row with a prominent warning so
                 nobody flips it casually. Leave blank to keep the
                 existing slug. --}}
            <div class="border border-amber-200 bg-amber-50/60 rounded-lg p-4 space-y-2">
                <div class="flex items-start gap-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-700 mt-0.5 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M5 19h14a2 2 0 001.732-3L13.732 4a2 2 0 00-3.464 0L3.268 16A2 2 0 005 19z"/></svg>
                    <div>
                        <h4 class="text-[12px] font-semibold text-amber-900">URL slug — change with care</h4>
                        <p class="text-[11px] text-amber-800 mt-0.5 leading-relaxed">
                            Changing the slug rewrites the workspace URL from
                            <code class="bg-amber-100 px-1 rounded">/t/{{ $tenant->slug }}</code>
                            to <code class="bg-amber-100 px-1 rounded">/t/&lt;new-slug&gt;</code>.
                            <strong>All bookmarks pointing at the old slug will 404</strong>, and tenant users with
                            an active session will lose their tenant context until they re-navigate. Audit history
                            is preserved (this change is recorded in the audit log with both before/after slugs).
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
                    <div>
                        <label class="block text-[11px] font-medium text-slate-600 mb-1">URL slug</label>
                        <div class="flex items-center gap-1">
                            <span class="text-[12px] font-mono text-slate-400 select-none">/t/</span>
                            <input name="slug" maxlength="64" pattern="[a-z][a-z0-9\-]+[a-z0-9]"
                                   value="{{ old('slug', $tenant->slug) }}"
                                   placeholder="{{ $tenant->slug }}"
                                   class="flex-1 border border-amber-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-amber-500 focus:border-transparent bg-white">
                        </div>
                        <p class="text-[10px] text-amber-700 mt-1">
                            Lowercase letters, digits and hyphens · 3–64 chars · must start with a letter and end alphanumerically. Leave unchanged to keep the current slug.
                        </p>
                    </div>
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
            <div class="flex items-center justify-between gap-2 mb-3">
                <h2 class="text-sm font-semibold text-slate-900">Users ({{ $users->count() }})</h2>
                <a href="/admin/users/create?tenant={{ $tenant->id }}"
                   class="inline-flex items-center gap-1 text-[11px] font-semibold text-white bg-indigo-600 hover:bg-indigo-700 px-2.5 py-1 rounded-md shadow-sm"
                   title="Create a new user under this tenant">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add user
                </a>
            </div>
            <div class="space-y-2 text-sm">
                @forelse ($users as $u)
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-slate-800 truncate">{{ $u->name }}</div>
                            <div class="text-[11px] text-slate-500 truncate">{{ $u->email }}</div>
                        </div>
                        <a href="/admin/users/{{ $u->id }}/edit"
                           class="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-slate-100 text-slate-600 hover:bg-indigo-100 hover:text-indigo-700"
                           title="Edit user — change role, permissions, password">{{ $u->role }}</a>
                    </div>
                @empty
                    <p class="text-xs text-slate-400 italic">No users yet — click <em>Add user</em> above.</p>
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
