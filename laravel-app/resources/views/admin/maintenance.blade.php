@extends('admin.layout')
@section('title', 'Maintenance')

@section('content')
<div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
    <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Deploy helper</div>
    <h1 class="text-xl font-bold text-slate-900">Maintenance</h1>
    <p class="text-[12px] text-slate-600 mt-2 max-w-3xl">
        Deploying = <strong>pull the code</strong> (hPanel → Git → Deploy, or the GitHub webhook) +
        <strong>clear the caches</strong> (the button below). No SSH session needed for either step.
    </p>
</div>

{{-- Deployed version --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
    <div class="panel p-5">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Deployed version</div>
        @if ($version)
            <div class="text-2xl font-bold mono text-slate-900">{{ $version['hash'] }}</div>
            <div class="text-[12px] text-slate-500 mt-1">
                @if ($version['branch']) branch <span class="mono font-semibold text-slate-700">{{ $version['branch'] }}</span> · @endif
                @if ($version['pulled_at']) last pulled <span class="font-semibold text-slate-700">{{ $version['pulled_at'] }}</span> @endif
            </div>
            <p class="text-[11px] text-slate-400 mt-3 leading-relaxed">
                Compare with the latest commit on
                <a href="https://github.com/Nekoutb/EA-Business-Intelligence/commits/master" target="_blank" rel="noopener" class="underline text-slate-600">GitHub master</a>.
                If they differ, the pull hasn't run yet.
            </p>
        @else
            <div class="text-sm text-slate-500">Could not read the git metadata on this server — the deployed
            commit can't be displayed. Cache clearing below still works.</div>
        @endif
    </div>

    {{-- Clear caches --}}
    <div class="panel p-5">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Clear caches</div>
        <p class="text-[12px] text-slate-600 leading-relaxed mb-4">
            Runs <span class="mono text-[11px]">view:clear · route:clear · config:clear · cache:clear</span> —
            makes freshly pulled code (views, routes, config) take effect and flushes cached Odoo analytics so
            pages recompute with current logic.
        </p>
        <form method="POST" action="/admin/maintenance/clear-caches">
            @csrf
            <button type="submit"
                    class="text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-4 py-2.5 shadow inline-flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Clear all caches
            </button>
        </form>
        <p class="text-[10.5px] text-slate-400 mt-3">
            Safe to run anytime — first page loads afterwards are slightly slower while caches rebuild.
        </p>
    </div>
</div>

{{-- One-time auto-deploy setup --}}
<div class="panel p-5">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">One-time setup · auto-deploy on merge</div>
    <ol class="text-[12px] text-slate-600 leading-relaxed list-decimal pl-5 space-y-1.5 max-w-3xl">
        <li>In <strong>hPanel → Websites → your site → Advanced → Git</strong>, make sure the repository is connected and note the <strong>webhook URL</strong> hPanel shows for the repo.</li>
        <li>On GitHub: <strong>repo → Settings → Webhooks → Add webhook</strong>, paste that URL, content type <span class="mono text-[11px]">application/json</span>, trigger on <em>push</em> events.</li>
        <li>From then on every merge to <span class="mono text-[11px]">master</span> pulls itself onto the server — you only press <strong>Clear all caches</strong> here afterwards.</li>
    </ol>
</div>
@endsection
