<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Admin') · Platform Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://rsms.me/"><link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>
      html { font-family: 'Inter', ui-sans-serif, system-ui; }
      body { background: #0f172a; }
      .panel { background: #ffffff; border-radius: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.02); }
    </style>
</head>
<body class="h-full antialiased text-slate-200">
<div class="min-h-full flex">
    {{-- Sidebar --}}
    <aside class="w-60 shrink-0 bg-slate-950 border-r border-slate-800 flex flex-col">
        <div class="px-5 py-5 flex items-center gap-3 border-b border-slate-800">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-rose-500 to-orange-500 flex items-center justify-center text-white font-bold text-xs shadow-lg">A</div>
            <div>
                <div class="text-[13px] font-semibold text-white">Platform Admin</div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider">Super Admin</div>
            </div>
        </div>
        @php $active = $active ?? ''; @endphp
        <nav class="flex-1 px-3 py-4 space-y-0.5 text-[13px]">
            @foreach ([
                ['dashboard',   '/admin',             'Dashboard',   'M3 12l9-9 9 9M5 10v10h14V10'],
                ['tenants',     '/admin/tenants',     'Tenants',     'M17 21v-2a4 4 0 0 0-3-3.87M9 21v-2a4 4 0 0 1 3-3.87M12 7a4 4 0 1 1 0 8 4 4 0 0 1 0-8z'],
                ['users',       '/admin/users',       'Users',       'M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM2 21v-2a6 6 0 0 1 6-6h8a6 6 0 0 1 6 6v2'],
                ['methodology', '/admin/methodology', 'Methodology', 'M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2zM22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z'],
                ['ai_settings', '/admin/settings/ai', 'AI Provider', 'M12 2a4 4 0 0 0-4 4v1H7a3 3 0 0 0-3 3v1a3 3 0 0 0 1 2.236V17a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3v-3.764A3 3 0 0 0 20 11v-1a3 3 0 0 0-3-3h-1V6a4 4 0 0 0-4-4zM9 14h.01M15 14h.01'],
            ] as [$slug, $url, $label, $path])
              <a href="{{ $url }}"
                 class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition
                        {{ $active === $slug ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-800/50 hover:text-white' }}">
                  <svg class="w-[15px] h-[15px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
                  <span>{{ $label }}</span>
              </a>
            @endforeach
        </nav>
        <div class="px-3 pb-4 space-y-0.5 text-[13px]">
            <div class="px-3 py-2 text-[11px] text-slate-500 truncate">{{ optional(request()->attributes->get('admin_user'))->email }}</div>
            <a href="/admin/logout" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-400 hover:bg-slate-800/50 hover:text-rose-400 transition">
                <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign out
            </a>
        </div>
    </aside>

    <main class="flex-1 min-w-0 bg-slate-100 text-slate-900">
        <div class="h-[3px] bg-gradient-to-r from-rose-500 via-orange-400 to-amber-400"></div>
        <div class="p-6 lg:px-8 lg:py-7">
            @hasSection('header')
              <div class="mb-7">@yield('header')</div>
            @endif

            @if (session('flash'))
              <div class="mb-4 text-sm rounded-lg border px-4 py-2.5
                {{ session('flash.type') === 'success'
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                    : 'bg-rose-50 border-rose-200 text-rose-800' }}">
                  {{ session('flash.msg') }}
              </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
