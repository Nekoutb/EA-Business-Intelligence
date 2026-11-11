@extends('admin.layout')
@section('title', $tenant->name . ' · data hosting')

@section('header')
    <div>
        <div class="flex items-center gap-2 mb-1">
            <a href="/admin/tenants/{{ $tenant->id }}" class="text-xs text-slate-500 hover:text-slate-800">← {{ $tenant->name }}</a>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Data hosting</h1>
        <p class="text-xs text-slate-500 mt-1 max-w-3xl">Where this client's business data lives: warehouse tables, report cache, budgets, commentaries and insights. Logins, settings and ERP credentials always stay on the EA platform.</p>
    </div>
@endsection

@section('content')
@php
    $target   = $cfg['target'] ?? null;
    $tgtDb    = $cfg['database'] ?? ($target === 'ea' ? $defaultDb : '');
    $locLabel = ['shared' => 'EA server · shared database', 'ea' => 'EA server · own database', 'client' => "Client's own server"];
    $locTone  = ['shared' => 'bg-slate-100 text-slate-700', 'ea' => 'bg-indigo-100 text-indigo-700', 'client' => 'bg-emerald-100 text-emerald-700'];
    $health   = $tenant->db_status;
    $hTone    = $health === 'ok' ? 'text-emerald-700' : ($health ? 'text-rose-700' : 'text-slate-500');
    $isShared = $location === 'shared';
    $formTarget = old('target', $target ?? 'ea');
@endphp

<div class="max-w-4xl space-y-4">

    @if ($errors->any())
        <div class="p-3 rounded-lg border border-rose-300 bg-rose-50 text-rose-900 text-sm">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    {{-- Current state --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Now</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Data lives on</div>
                <span class="text-[11px] font-semibold rounded px-2 py-1 {{ $locTone[$location] ?? '' }}">{{ $locLabel[$location] ?? $location }}</span>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Configured target</div>
                <div class="font-mono text-[12px] text-slate-800">
                    @if ($target)
                        {{ $target === 'ea' ? 'EA server' : ($cfg['host'] ?? '') . ':' . ($cfg['port'] ?? 3306) }} / {{ $tgtDb }}
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Health</div>
                <div class="text-[12px] {{ $hTone }}">
                    {{ $health ?? '—' }}
                    @if ($tenant->db_checked_at) · {{ $tenant->db_checked_at->diffForHumans() }} @endif
                    @if ($tenant->db_latency_ms !== null) · {{ $tenant->db_latency_ms }} ms @endif
                </div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Schema version</div>
                <div class="font-mono text-[12px] text-slate-800">{{ $tenant->db_schema_version ? substr($tenant->db_schema_version, 0, 12) : '—' }}</div>
            </div>
        </div>
    </div>

    {{-- 1. Target --}}
    <form method="POST" action="/admin/tenants/{{ $tenant->id }}/data-hosting" class="panel p-5" id="dh-form">
        @csrf
        <h2 class="text-sm font-semibold text-slate-900 mb-1">1 · Where should the data go?</h2>
        <p class="text-[12px] text-slate-500 mb-4">Saving only records the target. Nothing moves until step 4.</p>
        @unless ($isShared)
            <p class="text-[12px] text-amber-800 bg-amber-50 border border-amber-200 rounded p-2 mb-3">The client is live on its own database. To change the target, switch it back to the EA shared database first (bottom of the page).</p>
        @endunless

        <div class="grid sm:grid-cols-2 gap-3 mb-4">
            <label class="flex items-start gap-2 border rounded-lg p-3 cursor-pointer has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50/40">
                <input type="radio" name="target" value="ea" class="mt-1" {{ $formTarget === 'ea' ? 'checked' : '' }} {{ $isShared ? '' : 'disabled' }}>
                <span><span class="block text-sm font-semibold text-slate-900">EA server · own database</span>
                      <span class="block text-[11.5px] text-slate-500">A dedicated database next to the platform. Default name <code>{{ $defaultDb }}</code>.</span></span>
            </label>
            <label class="flex items-start gap-2 border rounded-lg p-3 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40">
                <input type="radio" name="target" value="client" class="mt-1" {{ $formTarget === 'client' ? 'checked' : '' }} {{ $isShared ? '' : 'disabled' }}>
                <span><span class="block text-sm font-semibold text-slate-900">Client's own server</span>
                      <span class="block text-[11.5px] text-slate-500">MySQL 8 / MariaDB 10.6+ that the client runs. BMS connects to it over the network.</span></span>
            </label>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600">Database name</span>
                <input name="database" value="{{ old('database', $cfg['database'] ?? '') }}" placeholder="{{ $defaultDb }}" {{ $isShared ? '' : 'disabled' }}
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
            </label>
            <div data-client-only class="grid grid-cols-3 gap-3">
                <label class="block col-span-2">
                    <span class="text-[11px] font-medium text-slate-600">Host</span>
                    <input name="host" value="{{ old('host', $cfg['host'] ?? '') }}" placeholder="db.client.cm" {{ $isShared ? '' : 'disabled' }}
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600">Port</span>
                    <input name="port" value="{{ old('port', $cfg['port'] ?? 3306) }}" {{ $isShared ? '' : 'disabled' }}
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            </div>
            <label class="block" data-client-only>
                <span class="text-[11px] font-medium text-slate-600">Username</span>
                <input name="username" value="{{ old('username', $cfg['username'] ?? '') }}" autocomplete="off" {{ $isShared ? '' : 'disabled' }}
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
            </label>
            <label class="block" data-client-only>
                <span class="text-[11px] font-medium text-slate-600">Password {{ $hasPassword ? '(stored — leave blank to keep)' : '' }}</span>
                <input name="password" type="password" value="" autocomplete="new-password" {{ $isShared ? '' : 'disabled' }}
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
            </label>
            <label class="block sm:col-span-2" data-client-only>
                <span class="text-[11px] font-medium text-slate-600">SSL CA certificate path on the EA server (recommended over the internet)</span>
                <input name="ssl_ca" value="{{ old('ssl_ca', $cfg['ssl_ca'] ?? '') }}" placeholder="/etc/bms/ca/{{ $tenant->slug }}.pem" {{ $isShared ? '' : 'disabled' }}
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
            </label>
        </div>

        <div class="mt-4 text-[11.5px] text-slate-600 bg-slate-50 border border-slate-200 rounded-lg p-3 leading-relaxed" data-ea-only>
            <strong>Before step 2</strong>, create the empty database once as MySQL root on the EA server:<br>
            <code class="block mt-1 font-mono text-[11px] break-all">mysql -u root -e "CREATE DATABASE IF NOT EXISTS `{{ $cfg['database'] ?? $defaultDb }}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON `{{ $cfg['database'] ?? $defaultDb }}`.* TO '{{ $appDbUser }}'@'localhost'; FLUSH PRIVILEGES;"</code>
        </div>
        <div class="mt-4 text-[11.5px] text-slate-600 bg-slate-50 border border-slate-200 rounded-lg p-3 leading-relaxed" data-client-only>
            <strong>What the client must provide:</strong> an empty database; a user with full rights on that database only;
            the database port reachable from the EA server's IP (firewall allow-list, never open to all); and preferably TLS, with its CA certificate sent to EA.
        </div>

        @if ($isShared)
            <div class="mt-4 flex justify-end">
                <button class="text-sm font-semibold bg-slate-900 hover:bg-slate-700 text-white px-4 py-2 rounded-lg">Save target</button>
            </div>
        @endif
    </form>

    {{-- 2–4. Steps --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-1">2–4 · Prepare and switch</h2>
        <p class="text-[12px] text-slate-500 mb-4">Run in order. Each step is safe to repeat; the EA shared copy is never deleted.</p>
        @unless ($canLaunch)
            <p class="text-[12px] text-amber-800 bg-amber-50 border border-amber-200 rounded p-2 mb-3">This server does not allow background jobs from the web: steps 3–4 will show the command to run over SSH instead.</p>
        @endunless

        <div class="space-y-3">
            <div class="flex items-center justify-between gap-4 border border-slate-200 rounded-lg p-3">
                <div><div class="text-sm font-semibold text-slate-900">2 · Test connection</div>
                     <div class="text-[11.5px] text-slate-500">Connects, reports latency, server version and which tables are missing.</div></div>
                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/data-hosting/check">@csrf
                    <button class="text-xs font-semibold border border-slate-300 hover:border-slate-500 rounded-lg px-3 py-2 disabled:opacity-40 disabled:cursor-not-allowed" {{ $target ? '' : 'disabled' }}>Test</button></form>
            </div>
            <div class="flex items-center justify-between gap-4 border border-slate-200 rounded-lg p-3">
                <div><div class="text-sm font-semibold text-slate-900">3a · Create tables</div>
                     <div class="text-[11.5px] text-slate-500">Creates the client tables in the target with the platform's structure.</div></div>
                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/data-hosting/run/provision">@csrf
                    <button class="text-xs font-semibold border border-slate-300 hover:border-slate-500 rounded-lg px-3 py-2 disabled:opacity-40 disabled:cursor-not-allowed" {{ ($target && $isShared && !$running) ? '' : 'disabled' }}>Create tables</button></form>
            </div>
            <div class="flex items-center justify-between gap-4 border border-slate-200 rounded-lg p-3">
                <div><div class="text-sm font-semibold text-slate-900">3b · Copy data (rehearsal)</div>
                     <div class="text-[11.5px] text-slate-500">Copies this client's rows and checks the counts match. The client keeps using the shared database.</div></div>
                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/data-hosting/run/copy">@csrf
                    <button class="text-xs font-semibold border border-slate-300 hover:border-slate-500 rounded-lg px-3 py-2 disabled:opacity-40 disabled:cursor-not-allowed" {{ ($target && $isShared && !$running) ? '' : 'disabled' }}>Copy</button></form>
            </div>
            <form method="POST" action="/admin/tenants/{{ $tenant->id }}/data-hosting/run/switch" class="flex items-center justify-between gap-4 border-2 border-indigo-200 rounded-lg p-3">@csrf
                <div><div class="text-sm font-semibold text-slate-900">4 · Switch</div>
                     <div class="text-[11.5px] text-slate-500">Pauses syncs, makes a final copy, checks counts, then points the client at its own database.</div></div>
                <div class="flex items-center gap-2">
                    <input name="confirm_slug" placeholder="type {{ $tenant->slug }}" autocomplete="off" class="border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-mono w-44" {{ ($target && $isShared && !$running) ? '' : 'disabled' }}>
                    <button class="text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg px-3 py-2 disabled:opacity-40" {{ ($target && $isShared && !$running) ? '' : 'disabled' }}>Switch</button>
                </div>
            </form>
            @unless ($isShared)
                <form method="POST" action="/admin/tenants/{{ $tenant->id }}/data-hosting/run/switch-back" class="flex items-center justify-between gap-4 border border-rose-200 bg-rose-50/40 rounded-lg p-3"
                      onsubmit="return confirm('Point this client back to the EA shared database? Data written to its own database since the switch is not copied back.')">@csrf
                    <div><div class="text-sm font-semibold text-rose-900">Switch back to EA shared</div>
                         <div class="text-[11.5px] text-rose-800">Immediate rollback. The client's own database is left as it is.</div></div>
                    <div class="flex items-center gap-2">
                        <input name="confirm_slug" placeholder="type {{ $tenant->slug }}" autocomplete="off" class="border border-rose-300 rounded-lg px-2 py-1.5 text-xs font-mono w-44">
                        <button class="text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white rounded-lg px-3 py-2">Switch back</button>
                    </div>
                </form>
            @endunless
        </div>
    </div>

    {{-- Log --}}
    <div class="panel p-5">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-900">Log</h2>
            <span id="dh-running" class="text-[11px] font-semibold {{ $running ? 'text-indigo-700' : 'text-slate-400' }}">{{ $running ? 'Running…' : 'Idle' }}</span>
        </div>
        <pre id="dh-log" class="text-[11px] font-mono bg-slate-950 text-slate-100 rounded-lg p-3 max-h-96 overflow-auto whitespace-pre-wrap">{{ $log !== '' ? $log : 'No steps run yet.' }}</pre>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('dh-form');
    function sync() {
        var t = (form.querySelector('input[name=target]:checked') || {}).value;
        form.querySelectorAll('[data-client-only]').forEach(function (el) { el.style.display = t === 'client' ? '' : 'none'; });
        form.querySelectorAll('[data-ea-only]').forEach(function (el) { el.style.display = t === 'ea' ? '' : 'none'; });
    }
    form.querySelectorAll('input[name=target]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();

    var pre = document.getElementById('dh-log'), badge = document.getElementById('dh-running');
    pre.scrollTop = pre.scrollHeight;
    var wasRunning = {{ $running ? 'true' : 'false' }}, idleTicks = 0;
    function poll() {
        fetch('/admin/tenants/{{ $tenant->id }}/data-hosting/log', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.log) { pre.textContent = d.log; pre.scrollTop = pre.scrollHeight; }
                badge.textContent = d.running ? 'Running…' : 'Idle';
                badge.className = 'text-[11px] font-semibold ' + (d.running ? 'text-indigo-700' : 'text-slate-400');
                if (d.running) { wasRunning = true; idleTicks = 0; }
                else if (wasRunning) { location.reload(); return; }
                else idleTicks++;
                if (d.running || idleTicks < 6) setTimeout(poll, 4000);
            })
            .catch(function () { setTimeout(poll, 8000); });
    }
    setTimeout(poll, 2000);
})();
</script>
@endsection
