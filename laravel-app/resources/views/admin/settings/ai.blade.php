@extends('admin.layout')
@section('title', 'AI Settings')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">AI Provider</h1>
    <p class="text-xs text-slate-500 mt-1">Platform-wide. Picks the LLM that powers Elite Advisors for every tenant. Secrets are encrypted at rest. Leave the API key blank to keep the existing value.</p>
@endsection

@section('content')
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
