@extends('layouts.app')
@section('title', 'Onboarding: Service Configuration')

@section('content')
<div class="min-h-screen bg-slate-50 py-12">
    <div class="max-w-2xl mx-auto px-4">
        @include('onboarding.stepper', ['step' => 4])

        <div class="bg-white rounded-lg shadow-sm p-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Service Configuration</h2>
            <p class="text-slate-600 mb-8">Configure settings specific to your enabled services.</p>

            @if ($errors->any())
                <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-6">
                    <div class="font-semibold mb-2">Please fix the errors:</div>
                    @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
                </div>
            @endif

            <form method="POST" action="/t/{{ $tenant->slug }}/onboarding/step" class="space-y-6">
                @csrf
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="action" value="next">

                {{-- Tax Compliance Configuration --}}
                @if (in_array('tax_compliance', $enabledServices))
                    <fieldset class="border-t border-slate-200 pt-6">
                        <legend class="text-sm font-semibold text-slate-900 mb-4">Tax Compliance</legend>

                        <label class="block mb-4">
                            <span class="text-sm font-medium text-slate-700">Tax Regime</span>
                            <input type="text" name="tax_regime" value="{{ old('tax_regime', $data['tax_regime'] ?? '') }}"
                                   maxlength="100"
                                   class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="e.g., EU, UK, US">
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">VAT Number</span>
                            <input type="text" name="vat_number" value="{{ old('vat_number', $data['vat_number'] ?? '') }}"
                                   maxlength="191"
                                   class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="Your VAT registration number">
                        </label>
                    </fieldset>
                @endif

                {{-- Business Intelligence Configuration --}}
                @if (in_array('business_intelligence', $enabledServices))
                    <fieldset class="border-t border-slate-200 pt-6">
                        <legend class="text-sm font-semibold text-slate-900 mb-4">Business Intelligence</legend>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Primary Revenue Source</span>
                            <input type="text" name="revenue_source" value="{{ old('revenue_source', $data['revenue_source'] ?? '') }}"
                                   maxlength="191"
                                   class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="e.g., Odoo sales module">
                            <p class="mt-1 text-xs text-slate-500">Where we should pull revenue data from</p>
                        </label>
                    </fieldset>
                @endif

                <div class="flex gap-3 pt-6 border-t border-slate-200">
                    <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        ← Back
                    </button>
                    <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Continue →
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
