@extends('admin.layout')
@section('title', $editing ? 'Edit user' : 'New user')

@section('header')
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $editing ? 'Edit user · ' . $editing->email : 'New user' }}</h1>
            <p class="text-xs text-slate-500 mt-1">Roles set the coarse default · per-user overrides below opt the user in or out of specific services / modules / KPIs.</p>
        </div>
        <a href="/admin/users" class="text-[12px] font-medium text-slate-600 border border-slate-200 hover:border-slate-400 rounded-lg px-3 py-2">← All users</a>
    </div>
@endsection

@section('content')
@php
  $perm = function (string $bucket, string $key) use ($editing) {
      if (!$editing) return null;
      return method_exists($editing, 'permOverride')
          ? $editing->permOverride($bucket, $key)
          : null;
  };
  $tenantValue = $editing ? ($editing->tenant_id ? (string) $editing->tenant_id : 'platform') : 'platform';
@endphp

@if (isset($errors) && $errors->any())
  <div class="mb-3 p-3 rounded border border-rose-200 bg-rose-50 text-rose-800 text-[12px]">
      @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
  </div>
@endif

<form method="POST" action="{{ $editing ? '/admin/users/' . $editing->id : '/admin/users' }}" class="space-y-6 max-w-5xl">
    @csrf

    <div class="panel p-5 space-y-4">
        <h2 class="text-sm font-semibold text-slate-900">Identity</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Workspace</label>
                <select name="tenant_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                    <option value="platform" @selected(old('tenant_id', $tenantValue) === 'platform')>— Platform (super admin) —</option>
                    @foreach ($tenants as $t)
                      <option value="{{ $t->id }}" @selected(old('tenant_id', $tenantValue) === (string) $t->id)>
                          {{ $t->brand ?: $t->name }} ({{ $t->slug }})
                      </option>
                    @endforeach
                </select>
                <p class="text-[10px] text-slate-500 mt-1">Platform = super admin · Workspace = tenant user (role + overrides apply).</p>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Role</label>
                    <select name="role" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                        @foreach ($roleOptions as $val => $label)
                          <option value="{{ $val }}" @selected(old('role', $editing->role ?? 'viewer') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                    <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                        <option value="active"   @selected(old('status', $editing->status ?? 'active') === 'active')>Active</option>
                        <option value="disabled" @selected(old('status', $editing->status ?? 'active') === 'disabled')>Disabled</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Name</label>
                <input name="name" value="{{ old('name', $editing->name ?? '') }}" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                <input name="email" type="email" value="{{ old('email', $editing->email ?? '') }}" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-slate-600 mb-1">
                    Password
                    @if ($editing)<span class="text-slate-400 font-normal">· leave blank to keep current</span>@endif
                </label>
                <input name="password" type="password" autocomplete="new-password" minlength="8"
                       {{ $editing ? '' : 'required' }}
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono">
            </div>
        </div>
    </div>

    <div class="panel p-5 space-y-5">
        <div>
            <h2 class="text-sm font-semibold text-slate-900">Permission overrides</h2>
            <p class="text-xs text-slate-500">Each row defaults to the role's setting. Choose <b>Allow</b> or <b>Deny</b> only when this user should differ from the default. Super admins ignore overrides — keep them on Default.</p>
        </div>

        @php
          $tri = function (string $bucket, string $key, ?bool $current) {
              $rows = [
                  ''     => ['Default', 'text-slate-600 bg-slate-100 border-slate-200'],
                  'on'   => ['Allow',   'text-emerald-700 bg-emerald-50 border-emerald-200'],
                  'off'  => ['Deny',    'text-rose-700 bg-rose-50 border-rose-200'],
              ];
              $name = "perm[$bucket][$key]";
              $sel  = $current === null ? '' : ($current ? 'on' : 'off');
              ob_start();
              echo '<div class="inline-flex border border-slate-200 rounded overflow-hidden text-[10px] font-semibold">';
              foreach ($rows as $val => [$lbl, $cls]) {
                  $isSel = $sel === $val;
                  echo '<label class="cursor-pointer px-2 py-0.5 border-r border-slate-200 last:border-r-0 ' . ($isSel ? $cls : 'text-slate-400 bg-white') . '">';
                  echo '<input type="radio" name="' . htmlspecialchars($name) . '" value="' . $val . '" ' . ($isSel ? 'checked' : '') . ' class="sr-only">';
                  echo $lbl;
                  echo '</label>';
              }
              echo '</div>';
              return ob_get_clean();
          };
        @endphp

        <div>
            <h3 class="text-xs font-semibold text-slate-600 uppercase tracking-wider mb-2">Services</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                @foreach ($services as $s)
                  <div class="flex items-center justify-between border border-slate-200 rounded px-3 py-2 bg-slate-50/40">
                      <div class="text-[12px] text-slate-700 font-medium">{{ $s['label'] }}</div>
                      {!! $tri('services', $s['key'], $perm('services', $s['key'])) !!}
                  </div>
                @endforeach
            </div>
        </div>

        <div>
            <h3 class="text-xs font-semibold text-slate-600 uppercase tracking-wider mb-2">Modules</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                @foreach ($modules as $m)
                  <div class="flex items-center justify-between border border-slate-200 rounded px-3 py-2 bg-slate-50/40">
                      <div class="text-[12px] text-slate-700 font-medium">
                          {{ $m['label'] }}
                          <span class="ml-1 text-[9px] text-slate-400 uppercase tracking-wider">{{ $m['service'] ?? '' }}</span>
                      </div>
                      {!! $tri('modules', $m['key'], $perm('modules', $m['key'])) !!}
                  </div>
                @endforeach
            </div>
        </div>

        <div>
            <h3 class="text-xs font-semibold text-slate-600 uppercase tracking-wider mb-2">Dashboard KPIs</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                @foreach ($kpis as $k)
                  <div class="flex items-center justify-between border border-slate-200 rounded px-3 py-2 bg-slate-50/40">
                      <div class="text-[12px] text-slate-700 font-medium">
                          {{ $k['label'] ?? $k['key'] }}
                          <span class="ml-1 text-[9px] text-slate-400 uppercase tracking-wider">{{ $k['key'] }}</span>
                      </div>
                      {!! $tri('kpis', $k['key'], $perm('kpis', $k['key'])) !!}
                  </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3">
        <a href="/admin/users" class="text-[12px] font-medium text-slate-600 border border-slate-200 hover:border-slate-400 rounded-lg px-3 py-2">← Cancel</a>
        <button type="submit" class="text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-4 py-2 shadow">
            {{ $editing ? 'Save changes' : 'Create user' }}
        </button>
    </div>
</form>

@push('scripts')
<script>
  // Tri-state radio polish — wrap clicks to update visual state
  // (radios are sr-only). Same pattern as the tenant-side form.
  (function () {
    const groups = new Map();
    document.querySelectorAll('input[type="radio"][name^="perm["]').forEach(r => {
      const arr = groups.get(r.name) || []; arr.push(r); groups.set(r.name, arr);
    });
    groups.forEach((radios) => {
      radios.forEach(r => r.addEventListener('change', () => {
        radios.forEach(other => {
          const lbl = other.parentElement;
          if (other.checked) {
            const v = other.value;
            lbl.className = 'cursor-pointer px-2 py-0.5 border-r border-slate-200 last:border-r-0 ' + (
              v === 'on'  ? 'text-emerald-700 bg-emerald-50 border-emerald-200' :
              v === 'off' ? 'text-rose-700 bg-rose-50 border-rose-200' :
                            'text-slate-600 bg-slate-100 border-slate-200'
            );
          } else {
            lbl.className = 'cursor-pointer px-2 py-0.5 border-r border-slate-200 last:border-r-0 text-slate-400 bg-white';
          }
        });
      }));
    });
  })();
</script>
@endpush
@endsection
