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

      /* ── Accessibility: visible keyboard focus + skip link ──────────── */
      a:focus-visible, button:focus-visible, select:focus-visible,
      input:focus-visible, [tabindex]:focus-visible {
        outline: 2px solid #f43f5e; outline-offset: 2px; border-radius: 6px;
      }
      .adm-skip {
        position: fixed; top: -60px; left: 8px; z-index: 100;
        background: #f43f5e; color: #fff; padding: 8px 14px;
        border-radius: 8px; font-size: 12px; font-weight: 600;
        transition: top .15s ease;
      }
      .adm-skip:focus { top: 8px; }

      /* ── Mobile (≤1023px): off-canvas drawer sidebar ────────────────── */
      #admBurger, #admBackdrop { display: none; }
      @media (max-width: 1023px) {
        #admBurger {
          display: inline-flex; align-items: center; justify-content: center;
          position: fixed; top: 10px; left: 10px; z-index: 65;
          width: 40px; height: 40px; border-radius: 10px;
          background: #1e293b; color: #fff; border: 1px solid #334155;
          box-shadow: 0 2px 8px rgba(0,0,0,.25); cursor: pointer;
        }
        #admBurger svg { width: 20px; height: 20px; }
        .adm-shell.is-nav-open #admBurger { left: auto; right: 14px; }

        #admSidebar {
          position: fixed; top: 0; left: 0; bottom: 0; z-index: 60;
          transform: translateX(-100%); transition: transform .22s ease;
        }
        .adm-shell.is-nav-open #admSidebar { transform: translateX(0); box-shadow: 2px 0 28px rgba(0,0,0,.45); }

        #admBackdrop {
          display: block; position: fixed; inset: 0; z-index: 55;
          background: rgba(2,6,23,.6); opacity: 0; pointer-events: none;
          transition: opacity .22s ease;
        }
        .adm-shell.is-nav-open #admBackdrop { opacity: 1; pointer-events: auto; }
      }

      @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
          animation-duration: .001ms !important; transition-duration: .001ms !important;
        }
      }

      /* Top navigation progress bar — perceived-performance feedback on
         the slow Odoo-backed admin pages (Run-all insights, etc.). */
      .adm-progress {
        position: fixed; top: 0; left: 0; height: 3px; width: 0;
        background: linear-gradient(90deg, #f43f5e, #fb923c);
        box-shadow: 0 0 8px rgba(244,63,94,.5);
        z-index: 200; opacity: 0; pointer-events: none;
        transition: width .2s ease, opacity .3s ease;
      }
      .adm-progress.is-active { opacity: 1; }
      /* Busy state blocks accidental double-submit (e.g. Run-all, delete). */
      .is-busy { opacity: .65; cursor: progress !important; pointer-events: none; }
    </style>
</head>
<body class="h-full antialiased text-slate-200">
<a href="#admMain" class="adm-skip">Skip to main content</a>
<div id="admProgress" class="adm-progress" role="presentation" aria-hidden="true"></div>
<div class="min-h-full flex adm-shell">
    {{-- Mobile-only drawer toggle + backdrop (hidden ≥1024px via CSS). --}}
    <button type="button" id="admBurger"
            aria-label="Open navigation menu" aria-controls="admSidebar" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div id="admBackdrop"></div>

    {{-- Sidebar --}}
    <aside id="admSidebar" class="w-60 shrink-0 bg-slate-950 border-r border-slate-800 flex flex-col" aria-label="Admin navigation">
        <div class="px-5 py-5 flex items-center gap-3 border-b border-slate-800">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-rose-500 to-orange-500 flex items-center justify-center text-white font-bold text-xs shadow-lg">A</div>
            <div>
                <div class="text-[13px] font-semibold text-white">Platform Admin</div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wider">Super Admin</div>
            </div>
        </div>
        @php $active = $active ?? ''; @endphp
        <nav class="flex-1 px-3 py-4 space-y-0.5 text-[13px]" aria-label="Admin sections">
            @foreach ([
                ['dashboard',   '/admin',             'Dashboard',   'M3 12l9-9 9 9M5 10v10h14V10'],
                ['tenants',     '/admin/tenants',     'Tenants',     'M17 21v-2a4 4 0 0 0-3-3.87M9 21v-2a4 4 0 0 1 3-3.87M12 7a4 4 0 1 1 0 8 4 4 0 0 1 0-8z'],
                ['users',       '/admin/users',       'Users',       'M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM2 21v-2a6 6 0 0 1 6-6h8a6 6 0 0 1 6 6v2'],
                ['business_insights', '/admin/business-insights', 'Business Insights', 'M9.663 17h4.673M12 3v1M3 12h1M20 12h1M5.6 5.6l.7.7M18.4 5.6l-.7.7M8 14a4 4 0 1 1 8 0c0 1.5-.5 2.5-1.5 3.5L14 18h-4l-.5-.5C8.5 16.5 8 15.5 8 14z'],
                ['methodology', '/admin/methodology', 'Methodology', 'M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2zM22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z'],
                ['ai_settings', '/admin/settings/ai', 'AI Provider', 'M12 2a4 4 0 0 0-4 4v1H7a3 3 0 0 0-3 3v1a3 3 0 0 0 1 2.236V17a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3v-3.764A3 3 0 0 0 20 11v-1a3 3 0 0 0-3-3h-1V6a4 4 0 0 0-4-4zM9 14h.01M15 14h.01'],
                ['mail_diagnostic', '/admin/mail-diagnostic', 'Mail diagnostic', 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6'],
                ['maintenance',     '/admin/maintenance',     'Maintenance',     'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z'],
            ] as [$slug, $url, $label, $path])
              <a href="{{ $url }}"
                 @if($active === $slug) aria-current="page" @endif
                 class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition
                        {{ $active === $slug ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-800/50 hover:text-white' }}">
                  <svg class="w-[15px] h-[15px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $path }}"/></svg>
                  <span>{{ $label }}</span>
              </a>
            @endforeach
        </nav>
        <div class="px-3 pb-4 space-y-0.5 text-[13px]">
            <div class="px-3 py-2 text-[11px] text-slate-500 truncate">{{ optional(request()->attributes->get('admin_user'))->email }}</div>
            <a href="/admin/logout" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-400 hover:bg-slate-800/50 hover:text-rose-400 transition">
                <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign out
            </a>
        </div>
    </aside>

    <main id="admMain" tabindex="-1" class="flex-1 min-w-0 bg-slate-100 text-slate-900">
        <div class="h-[3px] bg-gradient-to-r from-rose-500 via-orange-400 to-amber-400"></div>
        <div class="p-6 pt-16 lg:px-8 lg:py-7">
            @hasSection('header')
              <div class="mb-7">@yield('header')</div>
            @endif

            @if (session('flash'))
              @php $admFlashOk = session('flash.type') === 'success'; @endphp
              <div class="adm-flash mb-4 text-sm rounded-lg border px-4 py-2.5 flex items-start gap-2
                {{ $admFlashOk ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' }}"
                   role="status" aria-live="polite" data-autohide="{{ $admFlashOk ? '6000' : '0' }}">
                  <svg class="w-4 h-4 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                      @if ($admFlashOk) <path d="M20 6 9 17l-5-5"/> @else <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/> @endif
                  </svg>
                  <span class="flex-1 min-w-0">{{ session('flash.msg') }}</span>
                  <button type="button" class="shrink-0 opacity-60 hover:opacity-100 font-bold text-base leading-none" aria-label="Dismiss message" onclick="this.closest('.adm-flash').remove()">&times;</button>
              </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

{{-- Mobile drawer toggle (no-op ≥1024px where burger/backdrop are hidden). --}}
<script>
  (function () {
    var shell    = document.querySelector('.adm-shell');
    var burger   = document.getElementById('admBurger');
    var backdrop = document.getElementById('admBackdrop');
    if (!shell || !burger) return;
    function setOpen(open) {
      shell.classList.toggle('is-nav-open', open);
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      burger.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
    }
    burger.addEventListener('click', function () { setOpen(!shell.classList.contains('is-nav-open')); });
    if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
    document.querySelectorAll('#admSidebar a').forEach(function (a) {
      a.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  })();
</script>

{{-- Nav progress bar + double-submit guard. --}}
<script>
  (function () {
    var bar = document.getElementById('admProgress');
    var timer = null;
    function startProgress() {
      if (!bar) return;
      bar.classList.add('is-active');
      var w = 8; bar.style.width = w + '%';
      clearInterval(timer);
      timer = setInterval(function () {
        w += Math.max(0.4, (92 - w) * 0.08);
        if (w >= 92) { w = 92; clearInterval(timer); }
        bar.style.width = w + '%';
      }, 220);
    }
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented) return;
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (a.target === '_blank' || a.hasAttribute('download')) return;
      if (href === '' || href.charAt(0) === '#' || href.toLowerCase().indexOf('javascript:') === 0) return;
      if (a.origin && a.origin !== window.location.origin) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      startProgress();
    });
    document.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;   // confirm() cancelled / Livewire AJAX
      startProgress();
      var btn = e.target.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
      if (btn) setTimeout(function () { btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true'); }, 0);
    });
    window.addEventListener('pageshow', function (e) {
      if (e.persisted && bar) { clearInterval(timer); bar.classList.remove('is-active'); bar.style.width = '0'; }
    });
    // Auto-hide success flash messages (errors persist).
    document.querySelectorAll('.adm-flash[data-autohide]').forEach(function (el) {
      var ms = parseInt(el.getAttribute('data-autohide'), 10);
      if (!ms || ms <= 0) return;
      setTimeout(function () {
        el.style.transition = 'opacity .3s ease'; el.style.opacity = '0';
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 320);
      }, ms);
    });
  })();
</script>
</body>
</html>
