@extends('layouts.app')
@section('title', 'Users · Settings')
@section('page-title', 'Users')

@section('content')
@php
  $me = auth()->user();
  $canManage = $me && (method_exists($me, 'isTenantAdmin') ? $me->isTenantAdmin() : false)
            || $me && (method_exists($me, 'isSuperAdmin') ? $me->isSuperAdmin() : false);
@endphp

<div class="max-w-5xl">
    <div class="flex items-end justify-between mb-4 gap-3">
        <div>
            <p class="text-xs text-slate-500">Users in this workspace · roles, status, and per-user permission overrides.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ tenant_url('/settings') }}" class="text-[11px] font-medium text-slate-600 border border-slate-200 hover:border-slate-400 rounded px-3 py-1.5">← All settings</a>
            @if ($canManage)
              <a href="{{ tenant_url('/settings/users/create') }}" class="text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded px-3 py-1.5">+ Add user</a>
            @endif
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <table class="w-full text-[13px]">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr class="text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold">
                    <th class="px-4 py-2.5">User</th>
                    <th class="px-4 py-2.5">Role</th>
                    <th class="px-4 py-2.5">Status</th>
                    <th class="px-4 py-2.5">Last login</th>
                    <th class="px-4 py-2.5 text-right">Overrides</th>
                    @if ($canManage)<th class="px-4 py-2.5 text-right">Manage</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                  @php
                      $perms = is_array($u->permissions) ? $u->permissions : (json_decode((string) $u->permissions, true) ?: []);
                      $overrideCount = count($perms['services'] ?? []) + count($perms['modules'] ?? []) + count($perms['kpis'] ?? []);
                      $roleColor = match (true) {
                          in_array($u->role, ['tenant_admin', 'owner']) => 'bg-brand-50 text-brand-700 border-brand-200',
                          $u->role === 'manager'                        => 'bg-sage-50 text-sage-700 border-sage-200',
                          $u->role === 'analyst'                        => 'bg-warm-50 text-warm-700 border-warm-200',
                          default                                       => 'bg-slate-50 text-slate-600 border-slate-200',
                      };
                      $statusColor = $u->status === 'active'
                          ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                          : 'bg-rose-50 text-rose-700 border-rose-200';
                  @endphp
                  <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                      <td class="px-4 py-3">
                          <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                          <div class="text-[11px] text-slate-500">{{ $u->email }}</div>
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
                      <td class="px-4 py-3 text-[11px] text-slate-500">
                          {{ $u->last_login_at?->diffForHumans() ?? '—' }}
                      </td>
                      <td class="px-4 py-3 text-right">
                          @if ($overrideCount > 0)
                            <span class="text-[11px] font-semibold text-brand-700">{{ $overrideCount }} override(s)</span>
                          @else
                            <span class="text-[11px] text-slate-400">role default</span>
                          @endif
                      </td>
                      @if ($canManage)
                      <td class="px-4 py-3 text-right">
                          <a href="{{ tenant_url('/settings/users/'.$u->id.'/edit') }}" class="text-[11px] font-semibold text-brand-700 hover:text-brand-900 mr-3">Edit</a>
                          <form method="POST" action="{{ tenant_url('/settings/users/'.$u->id.'/send-reset') }}" class="inline mr-3" onsubmit="return confirm('Email a password reset link to {{ $u->email }}?')">
                              @csrf
                              <button type="submit" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900">Send reset link</button>
                          </form>
                          @if (!$me || $me->id !== $u->id)
                            <form method="POST" action="{{ tenant_url('/settings/users/'.$u->id.'/delete') }}" class="inline" onsubmit="return confirm('Delete {{ $u->email }}?')">
                                @csrf
                                <button type="submit" class="text-[11px] font-semibold text-rose-700 hover:text-rose-900">Delete</button>
                            </form>
                          @endif
                      </td>
                      @endif
                  </tr>
                @empty
                  <tr>
                      <td colspan="{{ $canManage ? 6 : 5 }}" class="px-4 py-8 text-center text-[12px] text-slate-500">
                          No users yet. @if ($canManage)<a href="{{ tenant_url('/settings/users/create') }}" class="text-brand-700 underline">Add the first one</a>.@endif
                      </td>
                  </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (!$canManage)
      <p class="text-[11px] text-slate-500 mt-3">Read-only view. Only tenant admins can add or remove users.</p>
    @endif
</div>
@endsection
