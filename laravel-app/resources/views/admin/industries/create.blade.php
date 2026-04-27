@extends('admin.layout')
@section('title', 'Create Industry')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Industry</h1>
        <p class="text-xs text-slate-500 mt-1">Add a new industry classification</p>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @if ($errors->any())
        <div class="mb-4 p-4 bg-rose-50 border border-rose-200 rounded-lg">
            <div class="font-semibold text-rose-700 mb-2">Please fix the following errors:</div>
            <ul class="list-disc list-inside text-sm text-rose-700 space-y-1">
                @foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach
            </ul>
        </div>
    @endif

    <div class="panel p-6">
        <form method="POST" action="/admin/industries" class="space-y-4">
            @csrf

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Key (slug) <span class="text-red-500">*</span></span>
                <input type="text" name="key" value="{{ old('key') }}" required
                       pattern="[a-z0-9_-]+" maxlength="64"
                       class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                       placeholder="retail">
                <p class="text-[10px] text-slate-400 mt-1">Lowercase, alphanumeric, underscores and dashes only</p>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Name <span class="text-red-500">*</span></span>
                <input type="text" name="name" value="{{ old('name') }}" required maxlength="191"
                       class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                       placeholder="Retail">
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Description</span>
                <textarea name="description" rows="3" maxlength="1000"
                          class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                          placeholder="Brief description of this industry...">{{ old('description') }}</textarea>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">SVG Icon (24x24)</span>
                <textarea name="icon" rows="3" maxlength="1000"
                          class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent font-mono text-[11px]"
                          placeholder="M3 3h2l.4 2.6M7 13v4a2 2 0 01-2 2H3m0 0a2 2 0 01-2-2v-4...">{{ old('icon') }}</textarea>
                <p class="text-[10px] text-slate-400 mt-1">SVG path data for a 24x24 viewBox icon</p>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Sort Order</span>
                <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0"
                       class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </label>

            <label class="flex items-center gap-2 mt-4">
                <input type="checkbox" name="active" value="1" {{ old('active', true) ? 'checked' : '' }}
                       class="w-4 h-4 border border-slate-300 rounded focus:ring-2 focus:ring-indigo-500">
                <span class="text-xs font-medium text-slate-600">Active</span>
            </label>

            <div class="flex gap-3 pt-4 border-t border-slate-200">
                <a href="/admin/industries" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                    Cancel
                </a>
                <button type="submit" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                    Create Industry
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
