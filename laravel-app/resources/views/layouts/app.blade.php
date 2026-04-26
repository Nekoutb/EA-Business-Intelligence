<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f5f3ef]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('bi.brand') }}</title>

    {{-- Tailwind CDN (dev).  Swap for compiled CSS via Vite before prod. --}}
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] },
            colors: {
              brand:   { 50: '#f0f7f8', 100: '#d9eef0', 200: '#b0dce1', 300: '#7ac3cb', 400: '#4da8b4', 500: '#3b8f9c', 600: '#2d7280', 700: '#275c67', 800: '#254d56', 900: '#1e3d44' },
              warm:    { 50: '#fdf8f3', 100: '#faeee0', 200: '#f3d9bb', 300: '#e9be8c', 400: '#dfa05e', 500: '#d18840', 600: '#b97034', 700: '#9a582c', 800: '#7e4828', 900: '#683c24' },
              sage:    { 50: '#f4f7f4', 100: '#e3ebe3', 200: '#c7d7c8', 300: '#a0bba2', 400: '#759b78', 500: '#5a815e', 600: '#466849', 700: '#3a533c', 800: '#304432', 900: '#28382a' },
              coral:   { 50: '#fdf5f3', 100: '#fae8e3', 200: '#f5d1c8', 300: '#ecb3a3', 400: '#df8a74', 500: '#d06a50', 600: '#bc5239', 700: '#9e422e', 800: '#833929', 900: '#6e3226' },
            },
            animation: {
              'fade-in': 'fadeIn 0.4s ease-out',
              'slide-up': 'slideUp 0.3s ease-out',
              'shimmer': 'shimmer 2s infinite linear',
            },
            keyframes: {
              fadeIn:  { '0%': { opacity: 0 },            '100%': { opacity: 1 } },
              slideUp: { '0%': { opacity: 0, transform: 'translateY(8px)' },
                         '100%': { opacity: 1, transform: 'translateY(0)' } },
              shimmer: { '0%': { backgroundPosition: '-500px 0' },
                         '100%': { backgroundPosition: '500px 0' } },
            },
          },
        },
      }
    </script>

    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">

    {{-- Alpine.js is bundled with Livewire 3 — do NOT load it separately --}}

    {{-- Chart.js via CDN for sparklines and bar/line charts --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    @livewireStyles

    <style>
      html { font-family: 'Inter', ui-sans-serif, system-ui; -webkit-font-smoothing: antialiased; }
      .kpi-card { transition: transform .15s ease, box-shadow .15s ease; }
      .kpi-card:hover { transform: translateY(-1px); box-shadow: 0 8px 24px -8px rgba(30,61,68,.12); }
      .section-card { background: #fff; border: 1px solid #e8e4de; border-radius: 1rem; }
      .accent-bar { width: 3px; border-radius: 3px; }
      .skeleton {
        background: linear-gradient(90deg, #ece8e1 0%, #f5f3ef 50%, #ece8e1 100%);
        background-size: 1000px 100%;
        animation: shimmer 1.8s infinite linear;
      }
      .scrollbar-thin::-webkit-scrollbar { width: 5px; }
      .scrollbar-thin::-webkit-scrollbar-thumb { background: rgba(148,163,184,.35); border-radius: 3px; }
      .table-row-hover:hover { background: #faf8f5; }
    </style>
</head>
<body class="h-full antialiased text-[#2d3748]">

<div class="min-h-full flex">

    {{-- ── Left sidebar ────────────────────────────────────────────────── --}}
    <aside class="w-60 shrink-0 bg-[#1c2637] text-slate-300 flex flex-col min-h-screen sticky top-0">
        <div class="px-5 py-5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-400 to-brand-600 flex items-center justify-center text-white font-bold text-xs shadow-md">
                {{ strtoupper(substr(config('bi.brand'), 0, 1)) }}
            </div>
            <div class="min-w-0">
                <div class="text-[13px] font-semibold text-white truncate leading-tight">{{ config('bi.brand', 'Elite Advisors Management Suite') }}</div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider">Management Suite</div>
            </div>
        </div>

        <div class="mx-4 h-px bg-slate-700/50"></div>

        <nav class="flex-1 px-3 py-4 space-y-3 text-[13px] overflow-y-auto scrollbar-thin">
            @php
              // Pull the service catalogue and module list from config/modules.php.
              // Sidebar groups modules by service so the suite's offerings (BI,
              // Tax Compliance, etc.) are visually distinct.
              $services = collect(config('modules.services', []))->sortBy('order');
              $allModules = collect(config('modules.modules', []))
                  ->filter(fn ($m) => !empty($m['url']));
              $active = $active ?? '';

              // Tenant-aware filtering: hide modules whose service or module
              // flag is off for the active tenant. Core modules always show.
              $tenantInScope = function_exists('tenant') ? tenant() : null;
              $isVisible = function ($module) use ($tenantInScope) {
                  if (!empty($module['core'])) return true;
                  if (!$tenantInScope) return true;
                  // Service must be enabled, AND module must be enabled.
                  if (method_exists($tenantInScope, 'serviceEnabled')
                      && !$tenantInScope->serviceEnabled($module['service'] ?? null)) {
                      return false;
                  }
                  return $tenantInScope->moduleEnabled($module['key']);
              };
            @endphp

            @foreach ($services as $service)
              @php
                $sModules = $allModules
                    ->where('service', $service['key'])
                    ->filter($isVisible)
                    ->values();
                $serviceComingSoon = ($service['status'] ?? 'live') === 'coming_soon';
              @endphp
              @if ($sModules->isEmpty() && !$serviceComingSoon) @continue @endif

              <div>
                <div class="flex items-center gap-2 px-3 pt-1 pb-1.5 text-[10px] uppercase tracking-[0.12em] text-slate-500 font-semibold">
                    <svg class="w-[12px] h-[12px] opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $service['icon'] }}"/></svg>
                    <span class="truncate">{{ $service['label'] }}</span>
                    @if ($serviceComingSoon)
                      <span class="ml-auto text-[8px] px-1.5 py-0.5 rounded bg-warm-700/30 text-warm-300 tracking-wider">SOON</span>
                    @endif
                </div>

                <div class="space-y-0.5">
                  @foreach ($sModules as $module)
                    @php $moduleSoon = ($module['status'] ?? 'live') === 'coming_soon'; @endphp
                    <a href="{{ $moduleSoon ? '#' : tenant_url($module['url']) }}"
                       @if($moduleSoon) onclick="return false" @endif
                       class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition text-[13px]
                              {{ $active === $module['active'] ? 'bg-brand-600/90 text-white shadow-sm shadow-brand-900/30' : ($moduleSoon ? 'text-slate-600 cursor-not-allowed' : 'text-slate-400 hover:bg-white/5 hover:text-white') }}">
                        <svg class="w-[15px] h-[15px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                          <path d="{{ $module['icon'] }}"/>
                        </svg>
                        <span class="truncate">{{ $module['label'] }}</span>
                        @if ($moduleSoon)
                          <span class="ml-auto text-[8px] px-1 py-0.5 rounded bg-slate-700/60 text-slate-400 tracking-wider">SOON</span>
                        @endif
                    </a>
                  @endforeach
                </div>
              </div>
            @endforeach
        </nav>

        <div class="px-3 pb-4 space-y-0.5 text-[13px]">
            <div class="mx-1 h-px bg-slate-700/50 mb-3"></div>
            <a href="{{ tenant_url('/settings') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-400 hover:bg-white/5 hover:text-white transition">
                <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Settings
            </a>
            <a href="{{ tenant_url('/logout') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-400 hover:bg-white/5 hover:text-coral-400 transition">
                <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </aside>

    {{-- ── Main + right AI rail ────────────────────────────────────────── --}}
    <div class="flex-1 min-w-0 flex flex-col">

        {{-- Top accent bar --}}
        <div class="h-[3px] bg-gradient-to-r from-brand-400 via-warm-400 to-sage-400 shrink-0"></div>

        <div class="flex-1 min-w-0 flex">
            <main class="flex-1 min-w-0 p-6 lg:px-8 lg:py-7 animate-fade-in">
                {{-- Page header slot --}}
                @hasSection('header')
                  <div class="mb-7">@yield('header')</div>
                @endif

                {{-- Flash messages --}}
                @if (session('flash'))
                  <div class="mb-4 text-sm rounded-lg border px-4 py-2.5
                    {{ session('flash.type') === 'success'
                        ? 'bg-sage-50 border-sage-200 text-sage-800'
                        : 'bg-coral-50 border-coral-200 text-coral-800' }}">
                      {{ session('flash.msg') }}
                  </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
