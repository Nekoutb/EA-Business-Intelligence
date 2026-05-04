@extends('admin.layout')
@section('title', 'Users')

@section('header')
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Users</h1>
            <p class="text-xs text-slate-500 mt-1">Cross-tenant user management. Filter by tenant, search by name or email, edit roles + per-user permission overrides.</p>
        </div>
        <a href="/admin/users/create"
           class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-3.5 py-2 shadow transition">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            New user
        </a>
    </div>
@endsection

@section('content')
<form method="GET" class="panel p-3 mb-4 flex gap-2">
    <input name="q" value="{{ $search }}" placeholder="Search name or email..."
           class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
    <select name="tenant" class="border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">All tenants</option>
        <option value="platform" @selected($tenantId === 'platform')>— Platform (super admins) —</option>
        @foreach ($tenants as $t)
          <option value="{{ $t->id }}" @selected((string) $tenantId === (string) $t->id)>{{ $t->brand ?: $t->name }} ({{ $t->slug }})</option>
        @endforeach
    </select>
    <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2">Filter</button>
</form>

<div class="panel overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-4 py-3 text-left">User</th>
                <th class="px-4 py-3 text-left">Tenant</th>
                <th class="px-4 py-3 text-left">Role</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-right">Overrides</th>
                <th class="px-4 py-3 text-right">Last login</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($users as $u)
              @php
                  $perms = is_array($u->permissions) ? $u->permissions : (json_decode((string) $u->permissions, true) ?: []);
                  $overrideCount = count($perms['services'] ?? []) + count($perms['modules'] ?? []) + count($perms['kpis'] ?? []);
                  $roleColor = match (true) {
                      $u->role === 'super_admin'                    => 'bg-rose-50 text-rose-700 border-rose-200',
                      in_array($u->role, ['tenant_admin','owner'])  => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                      $u->role === 'manager'                        => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                      $u->role === 'analyst'                        => 'bg-amber-50 text-amber-700 border-amber-200',
                      default                                       => 'bg-slate-50 text-slate-600 border-slate-200',
                  };
                  $statusColor = $u->status === 'active'
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      : 'bg-rose-50 text-rose-700 border-rose-200';
              @endphp
              <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3">
                      <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                      <div class="text-[11px] text-slate-500">{{ $u->email }}</div>
                  </td>
                  <td class="px-4 py-3">
                      @if ($u->tenant_id)
                        <span class="font-mono text-xs text-slate-800">{{ $u->tenant?->slug }}</span>
                        @if ($u->tenant?->brand)
                          <div class="text-[10px] text-slate-500">{{ $u->tenant->brand }}</div>
                        @endif
                      @else
                        <span class="text-[11px] font-semibold text-rose-600">Platform</span>
                      @endif
                  </td>
                  <td class="px-4 py-3">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold {{ $roleColor }}">
                          {{ $u->roleLabel() }}
                      </span>
                  </td>
                  <td class="px-4 py-3">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold {{ $statusColor }}">
                          {{ ucfirst($u->status) }}
                      </span>
                  </td>
                  <td class="px-4 py-3 text-right">
                      @if ($overrideCount > 0)
                        <span class="text-[11px] font-semibold text-indigo-700">{{ $overrideCount }}</span>
                      @else
                        <span class="text-[11px] text-slate-400">role default</span>
                      @endif
                  </td>
                  <td class="px-4 py-3 text-right text-[11px] text-slate-500">
                      {{ $u->last_login_at?->diffForHumans() ?? '—' }}
                  </td>
                  <td class="px-4 py-3 text-right whitespace-nowrap">
                      <a href="/admin/users/{{ $u->id }}/edit" class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                      <form method="POST" action="/admin/users/{{ $u->id }}/delete" class="inline" onsubmit="return confirm('Delete {{ $u->email }}?')">
                          @csrf
                          <button type="submit" class="text-[11px] font-semibold text-rose-600 hover:text-rose-900">Delete</button>
                      </form>
                  </td>
              </tr>
            @empty
              <tr>
                  <td colspan="7" class="px-4 py-8 text-center text-[12px] text-slate-500">
                      No users match the current filter. <a href="/admin/users" class="text-indigo-600 underline">Clear filter</a>.
                  </td>
              </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3 text-[11px] text-slate-500">
    {{ count($users) }} user(s) shown · Tenant Admin / Manager / Analyst / Viewer roles · per-user overrides editable on each row.
</div>
@endsection
