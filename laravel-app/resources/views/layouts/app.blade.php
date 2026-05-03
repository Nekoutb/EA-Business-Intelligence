<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('bi.brand') }}</title>

    {{-- ── Tailwind (CDN dev build) ─────────────────────────────────────
         Kept for the existing module pages (revenue, P&L, receivables …)
         that rely on Tailwind utility classes. The brand palette is
         re-aligned to the v5 teal so utilities like bg-brand-500,
         text-brand-700 etc. blend with the new shell automatically.   --}}
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            fontFamily: { sans: ['Segoe UI', '-apple-system', 'BlinkMacSystemFont', 'system-ui', 'sans-serif'] },
            colors: {
              /* v5 teal — primary #0d6e6e */
              brand:   { 50: '#e6f1f1', 100: '#cce3e3', 200: '#99c7c7', 300: '#66abab', 400: '#338f8f', 500: '#0d6e6e', 600: '#0a5757', 700: '#084444', 800: '#063535', 900: '#042828' },
              warm:    { 50: '#fdf8f3', 100: '#faeee0', 200: '#f3d9bb', 300: '#e9be8c', 400: '#dfa05e', 500: '#d18840', 600: '#b97034', 700: '#9a582c', 800: '#7e4828', 900: '#683c24' },
              sage:    { 50: '#f4f7f4', 100: '#e3ebe3', 200: '#c7d7c8', 300: '#a0bba2', 400: '#759b78', 500: '#5a815e', 600: '#466849', 700: '#3a533c', 800: '#304432', 900: '#28382a' },
              coral:   { 50: '#fdf5f3', 100: '#fae8e3', 200: '#f5d1c8', 300: '#ecb3a3', 400: '#df8a74', 500: '#d06a50', 600: '#bc5239', 700: '#9e422e', 800: '#833929', 900: '#6e3226' },
            },
            animation: {
              'fade-in':  'fadeIn 0.3s ease-out',
              'slide-up': 'slideUp 0.25s ease-out',
              'shimmer':  'shimmer 1.8s infinite linear',
            },
            keyframes: {
              fadeIn:  { '0%': { opacity: 0 }, '100%': { opacity: 1 } },
              slideUp: { '0%': { opacity: 0, transform: 'translateY(6px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
              shimmer: { '0%': { backgroundPosition: '-500px 0' }, '100%': { backgroundPosition: '500px 0' } },
            },
          },
        },
      }
    </script>

    {{-- ECharts: used by the v5 dashboard and any chart-heavy module pages --}}
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    {{-- Chart.js: kept for legacy module pages still using <canvas> --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    @livewireStyles

    <style>
      /* ── EAMS v5 design tokens ───────────────────────────────────────
         These map 1:1 to bms-dashboard-preview-v5.html. Every page that
         uses the .ea-* classes inherits the v5 look automatically.    */
      :root {
        --ea-primary:       #0d6e6e;
        --ea-primary-dark:  #0a5757;
        --bg:               #f4f3f0;
        --surface:          #ffffff;
        --surface-alt:      #faf9f8;
        --text:             #111827;
        --text-muted:       #4b5563;
        --text-subtle:      #6b7280;
        --text-faint:       #9ca3af;
        --border:           #e5e7eb;
        --border-faint:     #f3f4f6;
        --pos:              #047857;
        --neg:              #b91c1c;
        --warn:             #b45309;
        --warn-bg:          #fef3c7;
        --warn-bd:          #fde68a;
        --pending-text:     #92400e;
        --r-sm: 3px; --r: 6px; --r-lg: 8px;
        --font:      'Segoe UI', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
        --font-mono: 'Cascadia Mono', 'Consolas', ui-monospace, monospace;
      }

      * { box-sizing: border-box; }
      html, body { margin: 0; padding: 0; height: 100%; }
      body {
        font-family: var(--font);
        background: var(--bg);
        color: var(--text);
        font-size: 13px;
        line-height: 1.4;
        font-feature-settings: 'tnum' 1;
        font-variant-numeric: tabular-nums;
        -webkit-font-smoothing: antialiased;
        overflow: hidden;
      }
      a { color: inherit; text-decoration: none; }

      /* ── App shell ──────────────────────────────────────────────── */
      .ea-app  { display: flex; height: 100vh; overflow: hidden; }
      .ea-main { flex: 1; min-width: 0; display: flex; flex-direction: column; overflow: auto; }
      .ea-main:not(.is-fit) { padding: 16px 20px; }
      .ea-main.is-fit       { overflow: hidden; padding: 10px 16px; }

      /* ── Sidebar (220px, light cream) ──────────────────────────── */
      .ea-sb {
        width: 220px; flex-shrink: 0;
        background: var(--surface);
        border-right: 1px solid var(--border);
        display: flex; flex-direction: column;
        overflow-y: auto;
      }
      .ea-sb__brand {
        display: flex; align-items: center; gap: 8px;
        padding: 12px 14px;
        border-bottom: 1px solid var(--border);
        flex-shrink: 0;
      }
      .ea-sb__brand-a {
        width: 28px; height: 28px; border-radius: 6px;
        background: var(--ea-primary); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font: 700 13px var(--font);
        flex-shrink: 0;
      }
      .ea-sb__brand-w  { min-width: 0; }
      .ea-sb__brand-n  { font: 700 11px var(--font); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .ea-sb__brand-s  { font: 600 8.5px var(--font); color: var(--text-subtle); letter-spacing: 0.14em; text-transform: uppercase; margin-top: 2px; }

      .ea-nav { padding: 6px 0; flex: 1; }
      .ea-nav__group { padding: 0 8px; }
      .ea-nav__group + .ea-nav__group { margin-top: 8px; }
      .ea-nav__h {
        font: 700 9px var(--font); color: var(--text-faint);
        letter-spacing: 0.12em; text-transform: uppercase;
        padding: 5px 8px; display: flex; align-items: center; gap: 6px;
      }
      .ea-nav__h svg { width: 10px; height: 10px; opacity: .7; flex-shrink: 0; }
      .ea-nav__h-l { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }

      .ea-nav__i {
        display: flex; align-items: center; gap: 8px;
        padding: 5px 8px; margin: 1px 0;
        border-radius: var(--r);
        font: 500 12px var(--font); color: var(--text-muted);
        line-height: 1.2;
        transition: background .12s, color .12s;
      }
      .ea-nav__i svg { width: 13px; height: 13px; flex-shrink: 0; opacity: .75; }
      .ea-nav__i-l { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .ea-nav__i:hover { background: var(--bg); color: var(--text); }
      .ea-nav__i.is-active {
        background: rgba(13,110,110,.10);
        color: var(--ea-primary);
        font-weight: 600;
      }
      .ea-nav__i.is-soon { color: var(--text-faint); cursor: default; }
      .ea-nav__i.is-soon:hover { background: transparent; }
      .ea-nav__b {
        margin-left: auto; padding: 0 5px;
        border-radius: 999px;
        font: 700 8px var(--font);
        letter-spacing: .04em; text-transform: uppercase;
        background: var(--warn-bg); color: var(--pending-text);
        border: 1px solid var(--warn-bd);
        flex-shrink: 0;
      }

      .ea-sb__foot {
        padding: 8px;
        border-top: 1px solid var(--border);
        flex-shrink: 0;
      }

      /* ── Reusable card primitives ──────────────────────────────── */
      .ea-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        padding: 12px 14px;
      }
      .ea-card--tight { padding: 8px 12px; }

      /* Color helpers */
      .ea-pos  { color: var(--pos);  }
      .ea-neg  { color: var(--neg);  }
      .ea-warn { color: var(--warn); }

      /* Legacy support: hover lift + shimmer used by older pages */
      .kpi-card { transition: transform .15s ease, box-shadow .15s ease; }
      .kpi-card:hover { transform: translateY(-1px); box-shadow: 0 8px 24px -8px rgba(13,110,110,.18); }
      .section-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); }
      .accent-bar   { width: 3px; border-radius: 3px; }
      .skeleton {
        background: linear-gradient(90deg, #ece8e1 0%, #f5f3ef 50%, #ece8e1 100%);
        background-size: 1000px 100%;
        animation: shimmer 1.8s infinite linear;
      }
      .scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
      .scrollbar-thin::-webkit-scrollbar-thumb { background: rgba(148,163,184,.35); border-radius: 3px; }
      .ea-main::-webkit-scrollbar { width: 8px; }
      .ea-main::-webkit-scrollbar-thumb { background: rgba(148,163,184,.30); border-radius: 4px; }
      .ea-sb::-webkit-scrollbar { width: 6px; }
      .ea-sb::-webkit-scrollbar-thumb { background: rgba(148,163,184,.30); border-radius: 3px; }
      .table-row-hover:hover { background: var(--surface-alt); }

      /* ── Master period filter (sidebar) ─────────────────────────── */
      .ea-mfilter {
        background: linear-gradient(to bottom, #f9fafb, var(--surface));
        border-bottom: 1px solid var(--border);
        padding: 10px 12px;
        flex-shrink: 0;
      }
      .ea-mfilter__l { font: 700 9px var(--font); color: var(--ea-primary); letter-spacing: .12em; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
      .ea-mfilter__l svg { width: 11px; height: 11px; }
      .ea-mfilter__row { display: flex; gap: 4px; margin-bottom: 6px; }
      .ea-mfilter__row:last-child { margin-bottom: 0; }
      .ea-mfilter__btn {
        flex: 1; border: 1px solid var(--border); background: var(--surface);
        padding: 4px 6px; font: 600 10px var(--font); color: var(--text-subtle);
        border-radius: var(--r-sm); cursor: pointer; transition: background .12s, color .12s;
        text-align: center; line-height: 1.2;
      }
      .ea-mfilter__btn:hover { background: var(--bg); color: var(--text); }
      .ea-mfilter__btn.is-active { background: var(--ea-primary); border-color: var(--ea-primary); color: #fff; }
      .ea-mfilter__btn.is-frozen { color: var(--text-faint); cursor: not-allowed; background: var(--surface-alt); }
      .ea-mfilter__btn.is-frozen:hover { background: var(--surface-alt); color: var(--text-faint); }
      .ea-mfilter__sel {
        flex: 1; border: 1px solid var(--border); background: var(--surface);
        padding: 3px 6px; font: 600 10px var(--font); color: var(--text-muted);
        border-radius: var(--r-sm); cursor: pointer; min-width: 0;
      }
      .ea-mfilter__sel:disabled { color: var(--text-faint); background: var(--surface-alt); cursor: not-allowed; }
      .ea-mfilter__hint { font: 500 9px var(--font); color: var(--text-faint); margin-top: 4px; line-height: 1.3; }

      /* ── Company badge (top-right of every page) ────────────────── */
      .ea-cobadge {
        display: flex; align-items: center; gap: 8px;
        padding: 4px 10px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r);
      }
      .ea-cobadge__logo {
        width: 32px; height: 32px;
        background: linear-gradient(135deg, var(--ea-primary), #338f8f);
        border-radius: var(--r-sm);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font: 700 13px var(--font);
        flex-shrink: 0;
        overflow: hidden;
      }
      .ea-cobadge__logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
      .ea-cobadge__t { display: flex; flex-direction: column; line-height: 1.1; min-width: 0; }
      .ea-cobadge__n { font: 700 11px var(--font); color: var(--text); white-space: nowrap; max-width: 160px; overflow: hidden; text-overflow: ellipsis; }
      .ea-cobadge__sub { font: 500 9px var(--font); color: var(--text-subtle); letter-spacing: .04em; text-transform: uppercase; margin-top: 1px; white-space: nowrap; max-width: 160px; overflow: hidden; text-overflow: ellipsis; }

      /* ── Standardised topbar (used by pages without @section('header')) */
      .ea-topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-shrink: 0; gap: 12px; }
      .ea-topbar__t { display: flex; align-items: baseline; gap: 12px; min-width: 0; }
      .ea-topbar__eb { font: 600 10px var(--font); color: var(--text-subtle); letter-spacing: .12em; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
      .ea-topbar__eb::before { content:''; width:5px; height:5px; border-radius: 50%; background: var(--ea-primary); }
      .ea-topbar__h { font: 700 20px var(--font); color: var(--text); letter-spacing: -0.02em; }
      .ea-topbar__c { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
      .ea-topbar__meta { font: 500 11px var(--font); color: var(--text-subtle); }
      .ea-topbar__meta b { color: var(--text); font-weight: 600; }
      .ea-refresh-btn { background: var(--ea-primary); color:#fff; border:0; border-radius: var(--r); padding: 4px 10px; font: 600 11px var(--font); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
      .ea-refresh-btn:hover { background: var(--ea-primary-dark); }
      .ea-refresh-btn svg { width: 12px; height: 12px; }

      /* When pages > viewport on smaller screens, allow body scroll */
      @media (max-width: 1100px) {
        body { overflow: auto; }
        .ea-app { height: auto; min-height: 100vh; }
      }
    </style>

    @stack('styles')
</head>
<body>

<div class="ea-app">

    {{-- ── Sidebar ─────────────────────────────────────────────────────
         Driven by config/modules.php. Group order = service.order;
         module visibility = tenant service+module flags. Active state
         comes from the page's $active variable (eg 'dashboard',
         'revenue'). This keeps a single source of truth across the
         sidebar, route guard, admin toggles and per-tenant flags.   --}}
    <aside class="ea-sb">
        {{-- Brand block — sidebar identity. Falls through to tenant.brand,
             tenant.name, then config('bi.brand'). Logo (if uploaded)
             replaces the letter avatar. --}}
        <a href="{{ tenant_url('/') }}" class="ea-sb__brand" style="text-decoration:none;">
            <div class="ea-sb__brand-a">
                @if (tenant_logo_url())
                  <img src="{{ tenant_logo_url() }}" alt="" style="width:100%; height:100%; object-fit:contain; background:#fff; border-radius:6px;">
                @else
                  {{ tenant_brand_initial() }}
                @endif
            </div>
            <div class="ea-sb__brand-w">
                <div class="ea-sb__brand-n">{{ tenant_brand_name() }}</div>
                <div class="ea-sb__brand-s">Management Suite</div>
            </div>
        </a>

        {{-- ── MASTER PERIOD FILTER ─────────────────────────────────
             One filter for the whole app. Submits GET to current URL;
             RememberPeriod middleware persists the choice in session
             so navigating between modules keeps the filter applied.  --}}
        @php
          $mfPeriod = current_period();
          $mfType   = $mfPeriod['type']  ?? 'ytd';
          $mfYear   = $mfPeriod['year']  ?? (int) date('Y');
          $mfMonth  = $mfPeriod['month'] ?? (int) date('m');
          // Pages can pin the master filter to a subset of period
          // types by yielding a comma-separated list of frozen
          // (disabled) types — eg @section('mfilter-frozen', 'mtd,month,yoy')
          // on the Receivables page locks the filter to YTD only.
          $mfFrozenList = trim($__env->yieldContent('mfilter-frozen', ''));
          $mfFrozen     = $mfFrozenList === '' ? [] : array_map('trim', explode(',', $mfFrozenList));
          $mfIsFrozen   = fn (string $t) => in_array($t, $mfFrozen, true);
          // If the active session period happens to be a frozen
          // type on this page (eg user picks MTD elsewhere then
          // navigates to Receivables), force-display YTD-active so
          // the user understands the constraint applies here.
          $mfDisplayType = $mfIsFrozen($mfType) ? 'ytd' : $mfType;
        @endphp
        <form method="GET" class="ea-mfilter">
            <div class="ea-mfilter__l">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3z"/></svg>
                Period · Master Filter
            </div>
            <div class="ea-mfilter__row">
                @foreach (['ytd' => 'YTD', 'mtd' => 'MTD', 'month' => 'Month', 'yoy' => 'YoY'] as $tk => $tlbl)
                  @php $frozen = $mfIsFrozen($tk); @endphp
                  <button
                      type="{{ $frozen ? 'button' : 'submit' }}"
                      name="period" value="{{ $tk }}"
                      @if ($frozen) disabled title="Locked on this page" @endif
                      class="ea-mfilter__btn {{ $mfDisplayType === $tk ? 'is-active' : '' }} {{ $frozen ? 'is-frozen' : '' }}">
                      {{ $tlbl }}
                  </button>
                @endforeach
            </div>
            <div class="ea-mfilter__row">
                <select name="year" onchange="this.form.submit()" class="ea-mfilter__sel" title="Year">
                    @for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--)
                      <option value="{{ $y }}" {{ $mfYear == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
                <select name="month" onchange="this.form.submit()" class="ea-mfilter__sel" title="Month (only used when Month is active)" @if($mfIsFrozen('month')) disabled @endif>
                    @foreach (range(1,12) as $m)
                      <option value="{{ $m }}" {{ $mfMonth == $m ? 'selected' : '' }}>{{ date('M', mktime(0,0,0,$m,1)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ea-mfilter__hint">
                @if (!empty($mfFrozen))
                  <b style="color:var(--warn);">YTD-only on this page</b> · {{ implode(', ', array_map('strtoupper', $mfFrozen)) }} disabled
                @else
                  Applies to <b style="color:var(--ea-primary);">every page</b> · synced via session
                @endif
            </div>
        </form>

        @php
          $services = collect(config('modules.services', []))->sortBy('order');
          $allModules = collect(config('modules.modules', []))
              ->filter(fn ($m) => !empty($m['url']));
          $active = $active ?? '';

          $tenantInScope = function_exists('tenant') ? tenant() : null;
          $isVisible = function ($module) use ($tenantInScope) {
              if (!empty($module['core'])) return true;
              if (!$tenantInScope) return true;
              if (method_exists($tenantInScope, 'serviceEnabled')
                  && !$tenantInScope->serviceEnabled($module['service'] ?? null)) {
                  return false;
              }
              return $tenantInScope->moduleEnabled($module['key']);
          };
        @endphp

        <nav class="ea-nav">
            @foreach ($services as $service)
              @php
                $sModules = $allModules
                    ->where('service', $service['key'])
                    ->filter($isVisible)
                    ->values();
                $serviceComingSoon = ($service['status'] ?? 'live') === 'coming_soon';
              @endphp
              @if ($sModules->isEmpty() && !$serviceComingSoon) @continue @endif

              <div class="ea-nav__group">
                  <div class="ea-nav__h">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $service['icon'] }}"/></svg>
                      <span class="ea-nav__h-l">{{ $service['label'] }}</span>
                      @if ($serviceComingSoon)
                        <span class="ea-nav__b">Soon</span>
                      @endif
                  </div>
                  @foreach ($sModules as $module)
                    @php $moduleSoon = ($module['status'] ?? 'live') === 'coming_soon'; @endphp
                    <a href="{{ $moduleSoon ? '#' : tenant_url($module['url']) }}"
                       @if($moduleSoon) onclick="return false" @endif
                       class="ea-nav__i {{ $active === $module['active'] ? 'is-active' : '' }} {{ $moduleSoon ? 'is-soon' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $module['icon'] }}"/></svg>
                        <span class="ea-nav__i-l">{{ $module['label'] }}</span>
                        @if ($moduleSoon)<span class="ea-nav__b">Soon</span>@endif
                    </a>
                  @endforeach
              </div>
            @endforeach
        </nav>

        <div class="ea-sb__foot">
            <a href="{{ tenant_url('/settings') }}" class="ea-nav__i {{ $active === 'settings' ? 'is-active' : '' }}" style="margin:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span class="ea-nav__i-l">Settings</span>
            </a>
            <a href="{{ tenant_url('/logout') }}" class="ea-nav__i" style="margin:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span class="ea-nav__i-l">Logout</span>
            </a>
        </div>
    </aside>

    {{-- ── Main ────────────────────────────────────────────────────────
         Pages opt into single-screen mode by yielding 'main-class' as
         'is-fit' (the v5 dashboard does this so charts size to the
         viewport without the page scrolling). All other pages get
         padding + auto-scroll inside main, and the sidebar stays
         sticky-visible regardless.                                   --}}
    <main class="ea-main @yield('main-class')">

        {{-- ── Standardised topbar ─────────────────────────────────────
             Pages can either:
               • Yield 'page-title' (preferred) — the layout renders the
                 v5 topbar with company badge, refresh, and meta.
               • Or define @section('header') (legacy) — the page owns
                 the entire header block; layout just inlines it.
             Pages that fully manage their own chrome (eg the v5 single-
             screen Dashboard) yield 'page-title' as empty AND set
             main-class to 'is-fit' to suppress the layout topbar.    --}}
        @hasSection('header')
          {{-- Legacy path: page-supplied header markup --}}
          <div style="margin-bottom: 16px; flex-shrink: 0;">@yield('header')</div>
        @elseif (trim($__env->yieldContent('page-title')) !== '')
          {{-- v5 path: layout renders the topbar; page just supplies
               the title (and optionally an eyebrow). --}}
          <div class="ea-topbar">
              <div class="ea-topbar__t">
                  <div>
                      <div class="ea-topbar__eb">@yield('page-eyebrow', ($periodLabel ?? 'YTD ' . date('Y')) . ' · ' . now()->format('d M H:i'))</div>
                      <div class="ea-topbar__h">@yield('page-title')</div>
                  </div>
              </div>
              <div class="ea-topbar__c">
                  <span class="ea-topbar__meta">Updated <b>{{ now()->format('H:i') }}</b> · {{ ea_last_sync_label() }}</span>
                  <form method="POST" action="{{ tenant_url('/sync') }}" style="margin:0;">
                      @csrf
                      <button type="submit" class="ea-refresh-btn" title="Force refresh">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                          Refresh
                      </button>
                  </form>
                  <div class="ea-cobadge" title="{{ tenant_brand_name() }}">
                      <div class="ea-cobadge__logo">
                          @if (tenant_logo_url())
                            <img src="{{ tenant_logo_url() }}" alt="">
                          @else
                            {{ tenant_brand_initial() }}
                          @endif
                      </div>
                      <div class="ea-cobadge__t">
                          <div class="ea-cobadge__n">{{ tenant_brand_name() }}</div>
                          <div class="ea-cobadge__sub">{{ optional(tenant())->name ?? 'Workspace' }}</div>
                      </div>
                  </div>
              </div>
          </div>
        @endif

        @if (session('flash'))
          <div style="margin-bottom: 12px; padding: 8px 12px; border-radius: var(--r); border: 1px solid; font-size: 12px; flex-shrink: 0;
                {{ session('flash.type') === 'success'
                    ? 'background:#ecfdf5; border-color:#a7f3d0; color:#065f46;'
                    : 'background:#fef2f2; border-color:#fecaca; color:#991b1b;' }}">
              {{ session('flash.msg') }}
          </div>
        @endif

        @yield('content')
    </main>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
