@extends('layouts.app')
@section('title', 'Onboarding: Company Information')

@section('content')
<div class="min-h-screen bg-slate-50 py-12">
    <div class="max-w-2xl mx-auto px-4">
        @include('onboarding.stepper', ['step' => 1])

        <div class="bg-white rounded-lg shadow-sm p-8">
            <h1 class="text-2xl font-bold text-slate-900 mb-2">Welcome to {{ config('bi.brand') }}!</h1>
            <p class="text-slate-600 mb-8">Let's set up your workspace. First, tell us about your company.</p>

            @if ($errors->any())
                <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-6">
                    <div class="font-semibold mb-2">Please fix the following errors:</div>
                    @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
                </div>
            @endif

            <form method="POST" action="/t/{{ $tenant->slug }}/onboarding/step" class="space-y-5">
                @csrf
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="action" value="next">

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Company Name</span>
                    <input type="text" name="company_name" value="{{ old('company_name', $data['company_name'] ?? '') }}"
                           maxlength="191"
                           class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="Acme Corporation">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Industry</span>
                    <input type="text" name="industry" value="{{ old('industry', $data['industry'] ?? '') }}"
                           maxlength="191"
                           class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="e.g., Technology, Retail, Manufacturing">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Tax ID</span>
                    <input type="text" name="tax_id" value="{{ old('tax_id', $data['tax_id'] ?? '') }}"
                           maxlength="191"
                           class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="e.g., VAT number, EIN">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Address</span>
                    <textarea name="address" maxlength="500" rows="3"
                              class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                              placeholder="Street address, City, State/Province">{{ old('address', $data['address'] ?? '') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Country/Region</span>
                    <input type="text" name="country" value="{{ old('country', $data['country'] ?? '') }}"
                           maxlength="100"
                           class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="e.g., United States, United Kingdom">
                </label>

                <div class="flex gap-3 pt-6 border-t border-slate-200">
                    <a href="/t/{{ $tenant->slug }}/" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        Skip
                    </a>
                    <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Continue →
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
