@extends('admin.layout')
@section('title', 'AI Settings')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">AI Provider</h1>
    <p class="text-xs text-slate-500 mt-1">Platform-wide. Picks the LLM that powers Elite Advisors for every tenant. Secrets are encrypted at rest. Leave the API key blank to keep the existing value.</p>
@endsection

@section('content')
{{-- What this key powers — visible at a glance so super admins know what
     stops working if they clear it. --}}
<div class="panel p-4 mb-4 max-w-2xl border-l-4 border-amber-400 bg-amber-50/40">
    <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1.5">This key powers</div>
    <ul class="text-[12px] text-slate-700 space-y-1 leading-relaxed">
        <li class="flex items-start gap-2">
            <span class="text-amber-600 mt-0.5">•</span>
            <span><strong>Business Insights</strong> — daily management-report narrative per tenant (<a href="/admin/business-insights" class="text-indigo-600 hover:underline">/admin/business-insights</a>)</span>
        </li>
        <li class="flex items-start gap-2">
            <span class="text-amber-600 mt-0.5">•</span>
            <span><strong>Demo NL Console</strong> — natural-language commands in the visitor demo sandbox (<a href="/demo/enter" target="_blank" class="text-indigo-600 hover:underline">/demo</a>). Without a key, demo visitors fall back to the canned forms.</span>
        </li>
        <li class="flex items-start gap-2">
            <span class="text-amber-600 mt-0.5">•</span>
            <span><strong>AI Advisor</strong> — on-demand insights on tenant modules (where wired).</span>
        </li>
    </ul>
    <div class="text-[10px] text-slate-500 mt-2.5 italic">
        Resolved via <code class="bg-white px-1 py-0.5 rounded border border-amber-200 font-mono">config_env('AI_API_KEY')</code> from <code class="bg-white px-1 py-0.5 rounded border border-amber-200 font-mono">global_settings</code> — only super admins can change it; tenants inherit the same provider automatically.
    </div>
</div>

<form method="POST" action="/admin/settings/ai" class="space-y-4 max-w-2xl">
    @csrf

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Provider</h2>

        @php $currentProvider = $values['AI_PROVIDER'] ?: 'anthropic'; @endphp
        <div class="grid sm:grid-cols-2 gap-3">
            @foreach ($providers as $key => $label)
                <label class="flex items-start gap-3 border rounded-lg p-3 cursor-pointer
                              {{ $currentProvider === $key ? 'border-rose-400 bg-rose-50/50' : 'border-slate-200 hover:border-slate-300' }}">
                    <input type="radio" name="AI_PROVIDER" value="{{ $key }}"
                           {{ $currentProvider === $key ? 'checked' : '' }}
                           class="mt-1 accent-rose-600"
                           data-suggested-model="{{ $modelHints[$key] ?? '' }}">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ $label }}</div>
                        <div class="text-[11px] text-slate-500 mt-0.5 font-mono">{{ $modelHints[$key] ?? '' }}</div>
                    </div>
                </label>
            @endforeach
        </div>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Credentials</h2>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">AI_MODEL</span>
                <input name="AI_MODEL" id="ai_model"
                       type="text"
                       value="{{ $values['AI_MODEL'] }}"
                       placeholder="{{ $modelHints[$currentProvider] ?? '' }}"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                <span class="block text-[10px] text-slate-400 mt-1">Leave blank to use the suggested default.</span>
            </label>

            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">AI_API_KEY</span>
                <input name="AI_API_KEY"
                       type="password"
                       value=""
                       placeholder="{{ !empty($values['__has_AI_API_KEY']) ? '•••• (already set, leave blank to keep)' : 'sk-ant-... or sk-...' }}"
                       autocomplete="off"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                <span class="block text-[10px] text-slate-400 mt-1">
                    Anthropic keys start with <span class="font-mono">sk-ant-</span>; OpenAI keys start with <span class="font-mono">sk-</span>.
                </span>
            </label>
        </div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="/admin" class="text-sm text-slate-500 hover:text-slate-800 px-3 py-2">Cancel</a>
        <button class="bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-semibold px-4 py-2 rounded-lg text-sm shadow transition">
            Save AI settings
        </button>
    </div>
</form>

<script>
    // Update the model placeholder when the operator switches provider so the
    // suggested default stays visible without forcing a page reload.
    document.querySelectorAll('input[name="AI_PROVIDER"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var hint = this.dataset.suggestedModel || '';
            var input = document.getElementById('ai_model');
            if (input) input.placeholder = hint;
        });
    });
</script>
@endsection
