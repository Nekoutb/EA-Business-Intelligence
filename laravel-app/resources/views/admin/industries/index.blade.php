@extends('admin.layout')
@section('title', 'Industries')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Industries</h1>
            <p class="text-xs text-slate-500 mt-1">Manage industry classifications and module bundles</p>
        </div>
        <a href="/admin/industries/create" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
            + Create Industry
        </a>
    </div>
@endsection

@section('content')
<div class="max-w-5xl">
    @if ($flash = session('flash'))
        <div class="mb-4 p-4 rounded-lg {{ $flash['type'] === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-rose-50 border border-rose-200 text-rose-700' }}">
            {{ $flash['msg'] }}
        </div>
    @endif

    <div class="panel p-4">
        <div class="mb-4 flex items-center gap-2">
            <form method="GET" action="/admin/industries" class="flex gap-2">
                <input type="text" name="q" value="{{ $q }}" placeholder="Search industries..."
                       class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <button type="submit" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition">Search</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Name</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Key</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Tenants</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($industries as $ind)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $ind->name }}</td>
                            <td class="px-4 py-3 text-slate-600"><code class="bg-slate-100 px-2 py-1 rounded text-xs">{{ $ind->key }}</code></td>
                            <td class="px-4 py-3 text-slate-600">{{ $ind->tenants_count }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-1 rounded-full text-xs font-medium {{ $ind->active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
                                    {{ $ind->active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="/admin/industries/{{ $ind->id }}/edit" class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">Edit</a>
                                    <a href="/admin/industries/{{ $ind->id }}/edit-modules" class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">Modules</a>
                                    @if ($ind->tenants_count === 0)
                                        <form method="POST" action="/admin/industries/{{ $ind->id }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-rose-600 hover:text-rose-700 text-sm font-medium" onclick="return confirm('Delete this industry?')">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No industries found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($industries->hasPages())
            <div class="mt-4 pt-4 border-t border-slate-200 flex items-center justify-between text-sm">
                {{ $industries->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
