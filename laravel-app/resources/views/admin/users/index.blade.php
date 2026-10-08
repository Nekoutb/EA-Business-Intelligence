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
{{-- ─────── One-time credentials banner — only shows after a user
     has just been created via /admin/users/create. The password
     is never persisted in plaintext, so this is the admin's one
     chance to copy it (besides the welcome email if it dispatched
     successfully). The banner is dismiss-only — refreshing the
     page or navigating away makes it disappear forever. ─────── --}}
@if (session()->has('new_user_credentials'))
    @php $nc = session('new_user_credentials'); @endphp
    <div class="mb-4 bg-gradient-to-r from-indigo-50 to-emerald-50 border border-indigo-200 rounded-xl p-5 shadow-sm" id="ea-new-user-banner">
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-4 h-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <h3 class="text-sm font-semibold text-slate-900">User created — credentials below</h3>
                </div>
                <p class="text-[12px] text-slate-600 mb-3">
                    Copy these now — the password is <strong>only shown once</strong> for security; it isn't stored in plaintext anywhere we can show it again.
                    @if ($nc['mail']['attempted'])
                        @if ($nc['mail']['error'])
                            <span class="block mt-1 text-rose-700">
                                <strong>Welcome email failed</strong> — share the credentials manually.
                                Reason: <code class="bg-rose-50 border border-rose-200 px-1 rounded text-[10.5px]">{{ $nc['mail']['error'] }}</code>
                                <a href="/admin/mail-diagnostic" class="underline font-semibold ml-1">Open mail diagnostic →</a>
                            </span>
                        @elseif ($nc['mail']['driver'] === 'log')
                            <span class="block mt-1 text-amber-700">
                                Mail driver is <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">log</code> —
                                the welcome email was written to <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">storage/logs/laravel.log</code>
                                instead of being delivered. Set <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">MAIL_MAILER=smtp</code>
                                + SMTP credentials in <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">.env</code> for real delivery, or share the credentials manually now.
                                <a href="/admin/mail-diagnostic" class="underline font-semibold ml-1">Open mail diagnostic →</a>
                            </span>
                        @else
                            <span class="block mt-1 text-emerald-700">
                                ✓ Welcome email dispatched via <code class="bg-emerald-50 border border-emerald-200 px-1 rounded text-[10.5px]">{{ $nc['mail']['driver'] }}</code>.
                            </span>
                        @endif
                    @else
                        <span class="block mt-1 text-slate-500">No welcome email sent — the credentials below are the only delivery channel.</span>
                    @endif
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Email</div>
                        <div class="flex items-center gap-2 mt-1">
                            <code class="flex-1 bg-white border border-slate-200 rounded px-2 py-1.5 text-[12px] font-mono text-slate-900 truncate select-all">{{ $nc['email'] }}</code>
                            <button type="button" onclick="navigator.clipboard?.writeText(this.previousElementSibling.textContent.trim()); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy',1500);"
                                    class="text-[11px] font-medium text-indigo-700 border border-indigo-300 rounded px-2 py-1.5 hover:bg-indigo-50">Copy</button>
                        </div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Temporary password</div>
                        <div class="flex items-center gap-2 mt-1">
                            <code class="flex-1 bg-white border border-slate-200 rounded px-2 py-1.5 text-[12px] font-mono text-slate-900 truncate select-all">{{ $nc['password'] }}</code>
                            <button type="button" onclick="navigator.clipboard?.writeText(this.previousElementSibling.textContent.trim()); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy',1500);"
                                    class="text-[11px] font-medium text-indigo-700 border border-indigo-300 rounded px-2 py-1.5 hover:bg-indigo-50">Copy</button>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Login link</div>
                    <div class="flex items-center gap-2 mt-1">
                        <code class="flex-1 bg-white border border-slate-200 rounded px-2 py-1.5 text-[12px] font-mono text-slate-700 truncate select-all">{{ $nc['login_url'] }}</code>
                        <button type="button" onclick="navigator.clipboard?.writeText(this.previousElementSibling.textContent.trim()); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy',1500);"
                                class="text-[11px] font-medium text-indigo-700 border border-indigo-300 rounded px-2 py-1.5 hover:bg-indigo-50">Copy</button>
                        <a href="{{ $nc['login_url'] }}" target="_blank" rel="noopener"
                           class="text-[11px] font-medium text-slate-500 border border-slate-300 rounded px-2 py-1.5 hover:bg-slate-50">Open ↗</a>
                    </div>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('ea-new-user-banner').remove()"
                    class="text-slate-400 hover:text-slate-700 text-lg leading-none flex-shrink-0" title="Dismiss">×</button>
        </div>
    </div>
@endif

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
                      <form method="POST" action="/admin/users/{{ $u->id }}/send-reset" class="inline mr-3" onsubmit="return confirm('Email a password reset link to {{ $u->email }}?')">
                          @csrf
                          <button type="submit" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900">Send reset link</button>
                      </form>
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
