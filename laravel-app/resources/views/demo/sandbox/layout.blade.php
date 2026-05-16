<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title', 'Demo Sandbox') · BMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        html { font-family: 'Hanken Grotesk', system-ui, sans-serif; }
        body { background: #FBF8F1; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        .panel { background: #fff; border-radius: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.02); }
    </style>
</head>
<body class="h-full antialiased text-slate-900">
@php
    $demoTenant  = request()->attributes->get('demo_tenant');
    $demoSession = request()->attributes->get('demo_session');
    $active = $active ?? '';
@endphp

<div class="min-h-full flex">
    {{-- Sidebar --}}
    <aside class="w-64 shrink-0 bg-stone-900 text-stone-200 border-r border-stone-800 flex flex-col">
        <div class="px-5 py-5 border-b border-stone-800">
            <div class="text-[10px] uppercase tracking-widest text-amber-400 font-semibold mb-1">Demo Sandbox</div>
            <div class="text-[14px] font-semibold text-white truncate">{{ $demoTenant?->brand ?: 'BMS Demo' }}</div>
            <div class="text-[11px] text-stone-400 mono truncate">{{ $demoTenant?->slug }}</div>
            @if ($demoSession)
                <div class="text-[10px] text-stone-500 mt-2">Expires {{ $demoSession->expires_at->diffForHumans() }}</div>
            @endif
        </div>

        <nav class="flex-1 px-3 py-4 space-y-0.5 text-[13px] overflow-y-auto">
            @foreach ([
                ['dashboard',   '/demo/sandbox',              'Dashboard',     'M3 12l9-9 9 9M5 10v10h14V10'],
                ['revenue',     '/demo/sandbox/revenue',      'Revenue',       'M3 3v18h18M7 14l4-4 4 4 5-5'],
                ['receivables', '/demo/sandbox/receivables',  'Receivables',   'M4 4h16v16H4zM4 12h16M9 4v16'],
                ['profit_loss', '/demo/sandbox/profit-loss',  'Profit & Loss', 'M12 2v20M2 12h20M5 5l14 14M5 19L19 5'],
            ] as [$slug, $url, $label, $path])
              <a href="{{ $url }}"
                 class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition
                        {{ $active === $slug ? 'bg-stone-800 text-white' : 'text-stone-400 hover:bg-stone-800/60 hover:text-white' }}">
                  <svg class="w-[15px] h-[15px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
                  <span>{{ $label }}</span>
              </a>
            @endforeach

            <div class="pt-4 mt-3 border-t border-stone-800">
                <div class="px-3 pb-1.5 text-[10px] uppercase tracking-widest text-stone-500 font-semibold">Sandbox controls</div>
                @foreach ([
                    ['data',    '/demo/sandbox/data',    'Data tables',  'M3 5h18M3 12h18M3 19h18'],
                    ['console', '/demo/sandbox/console', 'NL console',   'M6 6l4 6-4 6M14 18h6'],
                ] as [$slug, $url, $label, $path])
                  <a href="{{ $url }}"
                     class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition
                            {{ $active === $slug ? 'bg-amber-600/20 text-amber-300' : 'text-stone-400 hover:bg-stone-800/60 hover:text-white' }}">
                      <svg class="w-[15px] h-[15px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
                      <span>{{ $label }}</span>
                  </a>
                @endforeach
            </div>
        </nav>

        <div class="px-3 pb-4 space-y-0.5 text-[12px]">
            <form method="POST" action="/demo/sandbox/reset" onsubmit="return confirm('Reset all demo data to the original seed? Your edits will be lost.')">
                @csrf
                <button type="submit" class="w-full text-left flex items-center gap-2.5 px-3 py-2 rounded-lg text-stone-400 hover:bg-stone-800/60 hover:text-amber-300 transition">
                    <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Reset demo data
                </button>
            </form>
            <a href="/demo/exit" onclick="return confirm('End your demo session? Your sandbox will be deleted.')"
               class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-stone-400 hover:bg-stone-800/60 hover:text-rose-400 transition">
                <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Exit demo
            </a>
        </div>
    </aside>

    <main class="flex-1 min-w-0 bg-stone-50">
        <div class="h-[3px] bg-gradient-to-r from-amber-500 via-orange-400 to-rose-500"></div>

        {{-- Always-on disclaimer ribbon --}}
        <div class="bg-amber-50 border-b border-amber-200 px-6 py-2 flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-[11px] text-amber-800">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><strong>Sandbox demo · fictional data only.</strong> Edits affect only your private session. Real production BMS lives on bms.cm-ea.com.</span>
            </div>
            @if ($demoSession)
                <div class="text-[10px] text-amber-700 mono">expires {{ $demoSession->expires_at->format('H:i') }}</div>
            @endif
        </div>

        <div class="p-6 lg:px-8 lg:py-7">
            @hasSection('header')
                <div class="mb-6">@yield('header')</div>
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
