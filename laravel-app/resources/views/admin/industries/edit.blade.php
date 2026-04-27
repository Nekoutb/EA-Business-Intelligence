@extends('admin.layout')
@section('title', 'Edit Industry')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $industry->name }}</h1>
            <p class="text-xs text-slate-500 mt-1">Editing industry settings</p>
        </div>
        <div class="flex gap-2">
            <a href="/admin/industries/{{ $industry->id }}/edit-modules" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-lg transition">
                Edit Modules
            </a>
            <a href="/admin/industries" class="px-4 py-2 text-slate-600 hover:text-slate-900 text-sm font-medium">
                ← Back to Industries
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @if ($flash = session('flash'))
        <div class="mb-4 p-4 rounded-lg {{ $flash['type'] === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-rose-50 border border-rose-200 text-rose-700' }}">
            {{ $flash['msg'] }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-4 bg-rose-50 border border-rose-200 rounded-lg">
            <div class="font-semibold text-rose-700 mb-2">Please fix the following errors:</div>
            <ul class="list-disc list-inside text-sm text-rose-700 space-y-1">
                @foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach
            </ul>
        </div>
    @endif

    <div class="panel p-6">
        <form method="POST" action="/admin/industries/{{ $industry->id }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 mb-4">
                <p class="text-xs text-slate-600"><strong>Key:</strong> <code class="bg-white px-2 py-1 rounded text-[11px]">{{ $industry->key }}</code></p>
            </div>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Name <span class="text-red-500">*</span></span>
                <input type="text" name="name" value="{{ old('name', $industry->name) }}" required maxlength="191"
                       class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Description</span>
                <textarea name="description" rows="3" maxlength="1000"
                          class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('description', $industry->description) }}</textarea>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">SVG Icon (24x24)</span>
                <textarea name="icon" rows="3" maxlength="1000"
                          class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent font-mono text-[11px]">{{ old('icon', $industry->icon) }}</textarea>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600">Sort Order</span>
                <input type="number" name="sort_order" value="{{ old('sort_order', $industry->sort_order) }}" min="0"
                       class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </label>

            <label class="flex items-center gap-2 mt-4">
                <input type="checkbox" name="active" value="1" {{ old('active', $industry->active) ? 'checked' : '' }}
                       class="w-4 h-4 border border-slate-300 rounded focus:ring-2 focus:ring-indigo-500">
                <span class="text-xs font-medium text-slate-600">Active</span>
            </label>

            <div class="flex gap-3 pt-4 border-t border-slate-200">
                <a href="/admin/industries" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                    Cancel
                </a>
                <button type="submit" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
