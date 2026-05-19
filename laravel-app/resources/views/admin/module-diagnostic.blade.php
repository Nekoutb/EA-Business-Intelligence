@extends('admin.layout')
@section('title', 'Module diagnostic')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Diagnostic</div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $moduleCfg['label'] ?? $moduleKey }}</h1>
        <p class="text-xs text-slate-500 mt-1">Tenant: <strong>{{ $tenant->brand ?: $tenant->name }}</strong> ({{ $tenant->slug }}) · Expected URL: <code class="bg-slate-100 px-1 rounded mono text-[11px]">{{ $expectedFullUrl }}</code></p>
    </div>
@endsection

@section('content')

<div class="mb-4">
    <a href="/admin/tenants/{{ $tenant->id }}" class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to {{ $tenant->slug }}
    </a>
</div>

@if ($allPass)
    <div class="mb-5 panel p-4 border-l-4 border-emerald-400 bg-emerald-50/40">
        <div class="text-sm font-semibold text-emerald-700">All gates pass — the module should be reachable</div>
        <p class="text-[12px] text-slate-700 mt-1">If the live page still shows 404 in the browser, the culprit is almost certainly server-level PHP caching: <strong>OPCache</strong> or <strong>PHP-FPM workers</strong> have the old compiled <code class="mono text-[11px]">routes/web.php</code> in memory. Either restart PHP-FPM or run <code class="mono text-[11px]">php artisan optimize:clear</code> to flush both Laravel and OPCache.</p>
    </div>
@else
    <div class="mb-5 panel p-4 border-l-4 border-rose-400 bg-rose-50/40">
        <div class="text-sm font-semibold text-rose-700">One or more gates are failing — see the checks below</div>
        <p class="text-[12px] text-slate-700 mt-1">Each failing row has a "Fix" command — run that, then refresh this page.</p>
    </div>
@endif

<div class="panel overflow-hidden mb-5">
    <table class="w-full text-[12.5px]">
        <thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="px-3 py-2.5 text-left w-12">Status</th>
                <th class="px-3 py-2.5 text-left">Check</th>
                <th class="px-3 py-2.5 text-left">Detail</th>
                <th class="px-3 py-2.5 text-left">Fix</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @foreach ($checks as $c)
                <tr>
                    <td class="px-3 py-2.5 text-center">
                        @if ($c['pass'])
                            <span class="inline-block w-5 h-5 rounded-full bg-emerald-500 text-white text-[11px] font-bold leading-5">✓</span>
                        @else
                            <span class="inline-block w-5 h-5 rounded-full bg-rose-500 text-white text-[11px] font-bold leading-5">✗</span>
                        @endif
                    </td>
                    <td class="px-3 py-2.5 font-medium text-slate-900">{{ $c['label'] }}</td>
                    <td class="px-3 py-2.5 text-slate-600 text-[11.5px] mono leading-snug">{{ $c['detail'] }}</td>
                    <td class="px-3 py-2.5 text-[11.5px]">
                        @if ($c['fix'])
                            <code class="bg-rose-50 border border-rose-200 text-rose-800 px-2 py-1 rounded mono text-[10.5px] inline-block">{{ $c['fix'] }}</code>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="panel p-5 bg-slate-900 text-slate-200 text-[12px] leading-relaxed">
    <div class="text-[10px] uppercase tracking-widest text-amber-400 font-semibold mb-2">Nuclear option · full cache flush</div>
    <p>If you've cleared Laravel's caches but the route still 404s, your PHP runtime is caching the old compiled <code class="mono bg-slate-800 px-1 rounded text-amber-200">routes/web.php</code>. SSH into the server and run, in order:</p>
    <pre class="mt-2 bg-slate-950 border border-slate-700 rounded p-3 text-[11px] text-amber-200 mono overflow-x-auto">cd /path/to/laravel-app
git status                                      # confirm pull worked
rm -f bootstrap/cache/*.php                     # nuke all Laravel caches
php artisan optimize:clear                      # clears config/routes/views/events
composer dump-autoload --optimize               # rebuild class map
sudo service php-fpm restart || sudo systemctl restart php8.3-fpm   # flush OPCache</pre>
    <p class="mt-3 text-slate-400 text-[11px]">The last command — restarting PHP-FPM — is the one that flushes the in-memory bytecode cache. On most Hostinger plans this is done via hPanel → Advanced → PHP Configuration → "Restart PHP" button.</p>
</div>

@endsection
