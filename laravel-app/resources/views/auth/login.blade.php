<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Elite Advisors · Business Management Suite</title>
    <meta name="description" content="Operating intelligence, audit-grade reporting, and tax compliance — engineered as a single principal-grade console. Built on top of Odoo.">
    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink: #0A0908;
            --ink-2: #14120E;
            --ink-3: #1F1C16;
            --line-dark: #2A2620;
            --line-darker: #1A1814;

            --cream: #F5F1EA;
            --cream-2: #EDE7DD;
            --cream-3: #DCD4C5;

            --gold: #C9A961;
            --gold-deep: #A88840;
            --gold-soft: rgba(201, 169, 97, 0.12);

            --muted-dark: #8C8780;
            --muted-light: #5A5650;

            --good: #8FA77E;
            --bad: #C68A6E;

            --line-light: rgba(10, 9, 8, 0.08);
            --line-mid: rgba(10, 9, 8, 0.18);
        }

        *, *::before, *::after {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        html, body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--cream);
            color: var(--ink);
            font-feature-settings: "ss01" 1;
        }
        body { overflow-x: hidden; }

        .font-display { font-family: 'Fraunces', 'Times New Roman', serif; font-feature-settings: "ss01" 1, "ss02" 1; }
        .font-mono    { font-family: 'JetBrains Mono', monospace; }

        .tracking-luxe     { letter-spacing: 0.18em; }
        .tracking-wordmark { letter-spacing: 0.34em; }
        .tracking-bms      { letter-spacing: 0.50em; }
        .num               { font-feature-settings: "tnum" 1, "lnum" 1, "ss01" 1; }

        /* ─────────────── Sticky nav with backdrop blur on scroll ─────────────── */
        .nav-bar {
            background: rgba(245, 241, 234, 0);
            backdrop-filter: blur(0);
            -webkit-backdrop-filter: blur(0);
            transition: background 0.4s ease, backdrop-filter 0.4s ease, border-color 0.4s ease, padding 0.4s ease;
            border-bottom: 1px solid transparent;
        }
        .nav-bar.scrolled {
            background: rgba(245, 241, 234, 0.85);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom-color: var(--line-light);
        }

        /* ─────────────── Reveal on load (hero) ───────────────────────────────── */
        @keyframes rise { 0% { opacity: 0; transform: translateY(18px); } 100% { opacity: 1; transform: translateY(0); } }
        .reveal { animation: rise 1.1s cubic-bezier(0.2,0.7,0.2,1) both; }

        /* ─────────────── Reveal on scroll (intersection observer) ────────────── */
        .scroll-reveal {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity 0.9s cubic-bezier(0.2,0.7,0.2,1), transform 0.9s cubic-bezier(0.2,0.7,0.2,1);
        }
        .scroll-reveal.is-visible { opacity: 1; transform: translateY(0); }

        /* ─────────────── Film-grain overlay (dark surfaces) ──────────────────── */
        .grain { position: relative; }
        .grain::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            opacity: 0.06; mix-blend-mode: overlay;
            background-image: url("data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        }

        /* ─────────────── Hairline rules ──────────────────────────────────────── */
        .rule       { height: 1px; background: var(--line-mid); }
        .rule-soft  { height: 1px; background: var(--line-light); }
        .rule-dark  { height: 1px; background: var(--line-dark); }

        /* ─────────────── Eyebrow text (label kicker) ─────────────────────────── */
        .eyebrow {
            display: inline-flex; align-items: center; gap: 0.7rem;
            font-size: 11px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.20em; color: var(--gold-deep);
        }
        .eyebrow::before { content: ''; display: block; width: 28px; height: 1px; background: currentColor; }
        .eyebrow.on-dark { color: var(--gold); }

        /* ─────────────── Form fields (hairline only) ─────────────────────────── */
        .field {
            background: transparent; border: 0; border-radius: 0;
            border-bottom: 1px solid #C9C2B2;
            padding: 14px 0 12px; width: 100%;
            font-family: 'DM Sans', sans-serif; font-size: 16px; color: var(--ink);
            transition: border-color 0.4s;
        }
        .field:focus { outline: none; border-bottom-color: var(--ink); }
        .field::placeholder { color: #B5AFA2; font-weight: 300; }
        .field-row { position: relative; }
        .field-row::after {
            content: ''; position: absolute; left: 0; right: 100%; bottom: 0; height: 1px;
            background: var(--gold); transition: right 0.6s cubic-bezier(0.2,0.7,0.2,1);
        }
        .field-row:focus-within::after { right: 0; }

        /* ─────────────── Buttons ─────────────────────────────────────────────── */
        .btn-primary {
            background: var(--ink); color: var(--cream);
            padding: 16px 24px; border: 0; cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px; font-weight: 500; letter-spacing: 0.20em; text-transform: uppercase;
            transition: background 0.35s, letter-spacing 0.35s, transform 0.35s;
            display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
        }
        .btn-primary:hover { background: var(--ink-3); letter-spacing: 0.24em; }
        .btn-primary .arrow { transition: transform 0.4s cubic-bezier(0.2,0.7,0.2,1); display: inline-block; }
        .btn-primary:hover .arrow { transform: translateX(6px); }

        .btn-ghost {
            background: transparent; color: var(--ink); border: 1px solid var(--line-mid);
            padding: 16px 24px; cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px; font-weight: 500; letter-spacing: 0.20em; text-transform: uppercase;
            transition: border-color 0.35s, color 0.35s;
            display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
        }
        .btn-ghost:hover { border-color: var(--ink); }

        .btn-nav {
            background: var(--ink); color: var(--cream);
            padding: 9px 18px; font-size: 12px; font-weight: 500; letter-spacing: 0.16em; text-transform: uppercase;
            transition: background 0.3s; display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .btn-nav:hover { background: var(--ink-3); }

        /* ─────────────── Section spacing utilities ───────────────────────────── */
        .section-pad { padding-top: 120px; padding-bottom: 120px; }
        @media (max-width: 768px) {
            .section-pad { padding-top: 80px; padding-bottom: 80px; }
        }

        /* ─────────────── Card hover ──────────────────────────────────────────── */
        .lift { transition: transform 0.5s cubic-bezier(0.2,0.7,0.2,1), box-shadow 0.5s ease, border-color 0.5s ease; }
        .lift:hover { transform: translateY(-4px); box-shadow: 0 30px 60px -30px rgba(10,9,8,0.18); }

        /* ─────────────── Capability card with gold corner ────────────────────── */
        .capability {
            position: relative; background: #FFFEF9; border: 1px solid var(--line-light);
            transition: border-color 0.4s, transform 0.4s;
        }
        .capability:hover { border-color: var(--ink); transform: translateY(-3px); }
        .capability::before {
            content: ''; position: absolute; top: 0; right: 0; width: 32px; height: 1px; background: var(--gold);
            transition: width 0.5s cubic-bezier(0.2,0.7,0.2,1);
        }
        .capability::after {
            content: ''; position: absolute; top: 0; right: 0; width: 1px; height: 32px; background: var(--gold);
            transition: height 0.5s cubic-bezier(0.2,0.7,0.2,1);
        }
        .capability:hover::before { width: 60px; }
        .capability:hover::after  { height: 60px; }

        /* ─────────────── Status pills ────────────────────────────────────────── */
        .pill {
            display: inline-flex; align-items: center; gap: 0.45rem;
            padding: 4px 10px; border: 1px solid; border-radius: 999px;
            font-size: 10px; font-weight: 500; letter-spacing: 0.18em; text-transform: uppercase;
        }
        .pill.live { color: #4D6B47; border-color: rgba(143,167,126,0.5); background: rgba(143,167,126,0.08); }
        .pill.soon { color: var(--muted-light); border-color: var(--line-mid); background: rgba(0,0,0,0.02); }
        .pill .dot { width: 6px; height: 6px; border-radius: 999px; background: currentColor; }
        @keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.45; transform: scale(0.85); } }
        .pill.live .dot { animation: pulse 2.2s ease-in-out infinite; }

        /* ─────────────── Dashboard mockup chrome ─────────────────────────────── */
        .mock-shell {
            background: #0A0908; color: var(--cream);
            border: 1px solid var(--line-dark);
            border-radius: 14px; overflow: hidden;
            box-shadow: 0 60px 120px -40px rgba(10,9,8,0.55), 0 24px 48px -16px rgba(10,9,8,0.35);
        }
        .mock-light {
            background: #FFFEF9; color: var(--ink);
            border: 1px solid var(--line-light);
            border-radius: 14px; overflow: hidden;
            box-shadow: 0 60px 120px -40px rgba(10,9,8,0.30), 0 24px 48px -16px rgba(10,9,8,0.18);
        }
        .mock-titlebar {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 14px; border-bottom: 1px solid var(--line-dark);
            background: rgba(255,255,255,0.02);
        }
        .mock-light .mock-titlebar { background: #FAF7F0; border-bottom-color: var(--line-light); }
        .mock-dot { width: 9px; height: 9px; border-radius: 999px; background: rgba(255,255,255,0.18); }
        .mock-light .mock-dot { background: rgba(10,9,8,0.16); }

        /* ─────────────── Section dividers ────────────────────────────────────── */
        .section-divider {
            position: relative; height: 1px; background: var(--line-light);
            display: flex; align-items: center; justify-content: center;
        }
        .section-divider span {
            background: var(--cream); padding: 0 1.25rem;
            font-size: 10px; font-weight: 500; letter-spacing: 0.34em; text-transform: uppercase; color: var(--gold-deep);
        }

        /* ─────────────── Anchor link hover underline ─────────────────────────── */
        .nav-link {
            position: relative; padding: 4px 0; font-size: 13px; font-weight: 400;
            color: var(--ink); transition: color 0.3s;
        }
        .nav-link::after {
            content: ''; position: absolute; left: 0; right: 100%; bottom: -2px; height: 1px;
            background: var(--ink); transition: right 0.4s cubic-bezier(0.2,0.7,0.2,1);
        }
        .nav-link:hover::after { right: 0; }

        /* ─────────────── Subtle gradient over hero (depth) ───────────────────── */
        .hero-bg {
            background:
                radial-gradient(circle 600px at 100% 100%, rgba(201,169,97,0.06), transparent 70%),
                var(--cream);
        }

        /* ─────────────── Marquee for compatibility strip (optional) ──────────── */
        .compat-grid > * { transition: opacity 0.4s; }
    </style>
</head>
<body class="h-full">

{{-- ═══════════════════════════════════════════════════════════════════════════
   STICKY TOP NAVIGATION
═══════════════════════════════════════════════════════════════════════════ --}}
<header id="navbar" class="nav-bar fixed top-0 left-0 right-0 z-50">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10 py-5 flex items-center justify-between">

        {{-- Brand / Logo --}}
        <a href="#top" class="flex items-center gap-3 group">
            {{-- Three-bar Elite Advisors mark — properly proportioned --}}
            <svg width="44" height="32" viewBox="0 0 44 32" fill="currentColor" class="text-[var(--ink)] block">
                <rect x="0" y="2"  width="44" height="6.5" rx="0.5"/>
                <rect x="0" y="12.75" width="44" height="6.5" rx="0.5"/>
                <rect x="0" y="23.5" width="44" height="6.5" rx="0.5"/>
            </svg>
            <div class="leading-tight">
                <div class="text-[12px] tracking-wordmark uppercase font-medium text-[var(--ink)]">Elite&nbsp;Advisors</div>
                <div class="text-[8.5px] tracking-bms uppercase font-medium text-[var(--gold-deep)] mt-0.5">Business&nbsp;Management&nbsp;Suite&nbsp;·&nbsp;BMS</div>
            </div>
        </a>

        {{-- Center nav --}}
        <nav class="hidden lg:flex items-center gap-9">
            <a href="#capabilities" class="nav-link">Capabilities</a>
            <a href="#dashboards"  class="nav-link">Dashboards</a>
            <a href="#integrations" class="nav-link">Integrations</a>
            <a href="#security"    class="nav-link">Security</a>
            <a href="#newsroom"    class="nav-link">Newsroom</a>
        </nav>

        {{-- Right cluster --}}
        <div class="flex items-center gap-5">
            <a href="https://www.eliteadvisors.cm-ea.com" target="_blank" rel="noopener"
               class="hidden md:inline-flex items-center gap-1.5 text-[11px] font-medium tracking-luxe uppercase text-[var(--muted-light)] hover:text-[var(--ink)] transition">
                eliteadvisors.cm-ea.com
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3h7v7M10 14L21 3M21 14v7H3V3h7"/></svg>
            </a>
            <a href="#signin" class="btn-nav">Sign in →</a>
        </div>
    </div>
</header>

{{-- ═══════════════════════════════════════════════════════════════════════════
   HERO — editorial left, login right
═══════════════════════════════════════════════════════════════════════════ --}}
<main id="top" class="hero-bg pt-24 lg:pt-28">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10">

        <div class="grid lg:grid-cols-[1.1fr_0.9fr] gap-12 lg:gap-16 xl:gap-24 pt-12 lg:pt-20 pb-24">

            {{-- ─────────── Left: editorial ─────────── --}}
            <div class="flex flex-col justify-center">

                <div class="eyebrow reveal" style="animation-delay: 0.05s;">
                    Business Management Suite · BMS
                </div>

                <h1 class="font-display text-[52px] sm:text-[64px] xl:text-[84px] 2xl:text-[96px]
                           leading-[0.94] tracking-[-0.022em] font-light mt-7 reveal" style="animation-delay: 0.18s;">
                    Operating intelligence,<br>
                    audit-grade reporting,<br>
                    <span class="italic font-normal text-[var(--gold-deep)]">one console.</span>
                </h1>

                <p class="text-[17px] xl:text-[19px] leading-[1.6] mt-9 max-w-xl font-light reveal"
                   style="color: var(--muted-light); animation-delay: 0.32s;">
                    Elite Advisors <strong class="font-medium text-[var(--ink)]">BMS</strong> unifies
                    real-time financial intelligence, statutory reporting, and tax compliance into a single
                    principal-grade workspace — engineered on top of <strong class="font-medium text-[var(--ink)]">Odoo</strong>,
                    designed for the way you actually run the firm.
                </p>

                {{-- Quick proof points (no fake numbers — real mod count from registry) --}}
                <div class="mt-12 flex flex-wrap gap-x-12 gap-y-5 reveal" style="animation-delay: 0.46s;">
                    @php
                        $svcCount = count(config('modules.services', []));
                        $modCount = count(config('modules.modules', []));
                    @endphp
                    <div>
                        <div class="font-display text-[34px] font-light leading-none num">{{ $svcCount }}</div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium mt-2" style="color: var(--muted-light);">Services</div>
                    </div>
                    <div>
                        <div class="font-display text-[34px] font-light leading-none num">{{ $modCount }}</div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium mt-2" style="color: var(--muted-light);">Modules</div>
                    </div>
                    <div>
                        <div class="font-display text-[34px] font-light leading-none num">8</div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium mt-2" style="color: var(--muted-light);">Industry templates</div>
                    </div>
                </div>

                <div class="mt-10 flex items-center gap-4 reveal" style="animation-delay: 0.6s;">
                    <a href="#capabilities" class="btn-ghost">Explore the suite</a>
                    <a href="#dashboards" class="text-[12px] tracking-luxe uppercase font-medium text-[var(--ink)] hover:text-[var(--gold-deep)] transition">
                        See it in motion →
                    </a>
                </div>

            </div>

            {{-- ─────────── Right: login form ─────────── --}}
            <div id="signin" class="lg:pl-6 xl:pl-12 self-center w-full reveal" style="animation-delay: 0.4s;">
                <div class="bg-white border border-[var(--line-light)] p-8 sm:p-10 max-w-md mx-auto"
                     style="box-shadow: 0 50px 100px -40px rgba(10,9,8,0.18), 0 18px 36px -12px rgba(10,9,8,0.10);">

                    <div class="flex items-baseline justify-between mb-7">
                        <div>
                            <div class="eyebrow">Sign in</div>
                            <h2 class="font-display text-[34px] leading-[0.95] tracking-tight font-light mt-3">
                                Welcome <span class="italic">back.</span>
                            </h2>
                        </div>
                    </div>

                    @if (session('flash'))
                      <div class="mb-5 px-4 py-3 text-[12px] leading-relaxed"
                           style="border: 1px solid rgba(198,138,110,0.4); background: #F8E8DD; color: #7A3D1F;">
                          {{ session('flash.msg') }}
                      </div>
                    @endif

                    <form method="POST" action="{{ $tenantSlug ? '/t/'.$tenantSlug.'/login' : '/login' }}" class="space-y-6">
                        @csrf
                        <div>
                            <label class="block text-[10px] tracking-luxe uppercase font-medium mb-2" style="color: rgba(10,9,8,0.5);">Username</label>
                            <div class="field-row">
                                <input name="username" autofocus required autocomplete="username"
                                       class="field" placeholder="principal@firm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] tracking-luxe uppercase font-medium mb-2" style="color: rgba(10,9,8,0.5);">Password</label>
                            <div class="field-row">
                                <input name="password" type="password" required autocomplete="current-password"
                                       class="field" placeholder="••••••••••">
                            </div>
                        </div>
                        <div class="pt-3">
                            <button type="submit" class="btn-primary w-full">
                                <span>Sign in to BMS</span>
                                <span class="arrow">→</span>
                            </button>
                        </div>
                    </form>

                    <div class="rule-soft mt-8 mb-5"></div>
                    <div class="flex items-start gap-3 text-[11px] leading-relaxed font-light" style="color: rgba(10,9,8,0.55);">
                        <svg class="w-3.5 h-3.5 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: var(--gold-deep);">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                        </svg>
                        <span>Sessions are encrypted, scoped to your engagement, and audit-logged in line with internal controls.</span>
                    </div>
                </div>
            </div>

        </div>

        {{-- Compatibility strip — small, factual --}}
        <div class="rule-soft"></div>
        <div class="py-7 flex flex-wrap items-center gap-x-10 gap-y-4 text-[11px] tracking-luxe uppercase font-medium scroll-reveal" style="color: var(--muted-light);">
            <span>Compatible with</span>
            <span class="flex items-center gap-2 text-[var(--ink)]">
                <span class="font-display text-base font-normal">Odoo</span>
                <span class="pill live"><span class="dot"></span>Live</span>
            </span>
            <span class="flex items-center gap-2"><span class="font-display text-base font-normal">SAP</span><span class="pill soon">Coming soon</span></span>
            <span class="flex items-center gap-2"><span class="font-display text-base font-normal">NetSuite</span><span class="pill soon">Coming soon</span></span>
            <span class="flex items-center gap-2"><span class="font-display text-base font-normal">Microsoft Dynamics</span><span class="pill soon">Coming soon</span></span>
            <span class="flex items-center gap-2"><span class="font-display text-base font-normal">Sage</span><span class="pill soon">Coming soon</span></span>
            <span class="flex items-center gap-2"><span class="font-display text-base font-normal">QuickBooks</span><span class="pill soon">Coming soon</span></span>
        </div>
        <div class="rule-soft"></div>
    </div>
</main>

{{-- ═══════════════════════════════════════════════════════════════════════════
   CAPABILITIES — 4 module cards
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="capabilities" class="section-pad">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10">

        <div class="grid lg:grid-cols-[1fr_2fr] gap-12 mb-16">
            <div class="scroll-reveal">
                <div class="eyebrow">Capabilities</div>
                <h2 class="font-display text-[44px] xl:text-[56px] leading-[0.96] tracking-tight font-light mt-5">
                    Four disciplines.<br>
                    <span class="italic font-normal text-[var(--gold-deep)]">One source of truth.</span>
                </h2>
            </div>
            <div class="scroll-reveal" style="transition-delay: 0.15s;">
                <p class="text-[17px] leading-relaxed font-light max-w-xl" style="color: var(--muted-light);">
                    Each service in BMS is built to the standard of a Big-Four engagement
                    — but folded into the operating cadence of the principal who actually
                    runs the day. No dashboards in search of a question. Every module
                    answers one.
                </p>
            </div>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-px bg-[var(--line-light)] scroll-reveal">

            {{-- Business Intelligence --}}
            <div class="capability p-8 lg:p-10 lg:min-h-[340px] flex flex-col">
                <div class="text-[var(--gold-deep)] mb-7">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 14l4-4 3 3 5-5"/>
                    </svg>
                </div>
                <h3 class="font-display text-[24px] leading-tight font-normal">Business Intelligence</h3>
                <p class="text-[14px] mt-4 leading-relaxed font-light" style="color: var(--muted-light);">
                    Live KPIs across revenue, margin, receivables and treasury — refreshed
                    from your ledger, drilled to the invoice.
                </p>
                <div class="mt-auto pt-6 text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">
                    Dashboard · Revenue · Customer Insights · Treasury
                </div>
            </div>

            {{-- Financial Reporting --}}
            <div class="capability p-8 lg:p-10 lg:min-h-[340px] flex flex-col">
                <div class="text-[var(--gold-deep)] mb-7">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 2h6l5 5v15H4V2zM9 2v6h6"/>
                    </svg>
                </div>
                <h3 class="font-display text-[24px] leading-tight font-normal">Financial Reporting</h3>
                <p class="text-[14px] mt-4 leading-relaxed font-light" style="color: var(--muted-light);">
                    Statutory and management reports — trial balance, balance sheet, P&L,
                    cash flow — backed by GL-quality reconciliation.
                </p>
                <div class="mt-auto pt-6 text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">
                    GL Quality · Trial Balance · Balance Sheet · Cash Flow
                </div>
            </div>

            {{-- Tax Compliance --}}
            <div class="capability p-8 lg:p-10 lg:min-h-[340px] flex flex-col">
                <div class="text-[var(--gold-deep)] mb-7">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M5 4h14a2 2 0 012 2v14l-4-2-3 2-3-2-4 2V6a2 2 0 012-2z"/>
                    </svg>
                </div>
                <h3 class="font-display text-[24px] leading-tight font-normal">Tax Compliance</h3>
                <p class="text-[14px] mt-4 leading-relaxed font-light" style="color: var(--muted-light);">
                    Tax-regime classification, VAT calendar, and invoice-level CGI
                    compliance checks — surfaced before they become exposure.
                </p>
                <div class="mt-auto pt-6 text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">
                    VAT Compliance · Invoice Compliance · Tax Calendar
                </div>
            </div>

            {{-- Financial Planning --}}
            <div class="capability p-8 lg:p-10 lg:min-h-[340px] flex flex-col">
                <div class="text-[var(--gold-deep)] mb-7">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 17l6-6 4 4 8-8M14 7h7v7"/>
                    </svg>
                </div>
                <h3 class="font-display text-[24px] leading-tight font-normal">Financial Planning</h3>
                <p class="text-[14px] mt-4 leading-relaxed font-light" style="color: var(--muted-light);">
                    Budgeting, forecasting and scenario modelling on top of your live
                    ledger. <span class="text-[var(--gold-deep)] font-medium">Coming Q2 2026.</span>
                </p>
                <div class="mt-auto pt-6 text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">
                    Budgeting · Forecasting · Scenario Modeling
                </div>
            </div>
        </div>

    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   DASHBOARDS — two high-fidelity inline mockups with commentary
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="dashboards" class="grain section-pad relative overflow-hidden" style="background: var(--ink); color: var(--cream);">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10 relative">

        {{-- Section header --}}
        <div class="grid lg:grid-cols-[1fr_2fr] gap-12 mb-16 scroll-reveal">
            <div>
                <div class="eyebrow on-dark">In motion</div>
                <h2 class="font-display text-[44px] xl:text-[56px] leading-[0.96] tracking-tight font-light mt-5">
                    A console you actually<br>
                    <span class="italic font-normal text-[var(--gold)]">read.</span>
                </h2>
            </div>
            <div class="self-end">
                <p class="text-[17px] leading-relaxed font-light max-w-xl" style="color: rgba(245,241,234,0.72);">
                    Two principal-grade views from inside BMS.
                    The intent of every screen is to surface the next decision worth making —
                    not to display every column the database has.
                </p>
            </div>
        </div>

        {{-- Dashboard 1 — Business Intelligence --}}
        <div class="grid lg:grid-cols-[1.6fr_1fr] gap-10 lg:gap-16 items-center mb-32 scroll-reveal">
            <div>
                {{-- BI Dashboard mockup --}}
                <div class="mock-light">
                    <div class="mock-titlebar">
                        <span class="mock-dot"></span><span class="mock-dot"></span><span class="mock-dot"></span>
                        <span class="ml-3 font-mono text-[10px]" style="color: var(--muted-light);">bms.eliteadvisors.cm-ea.com / dashboard</span>
                    </div>
                    <div class="grid grid-cols-[160px_1fr] min-h-[420px]">
                        {{-- Sidebar --}}
                        <aside class="border-r border-[var(--line-light)] py-5 px-4 bg-[#FAF7F0]">
                            <div class="flex items-center gap-2 mb-7">
                                <svg width="18" height="13" viewBox="0 0 44 32" fill="currentColor"><rect x="0" y="2" width="44" height="6.5"/><rect x="0" y="12.75" width="44" height="6.5"/><rect x="0" y="23.5" width="44" height="6.5"/></svg>
                                <span class="text-[8px] tracking-wordmark uppercase font-medium">Elite&nbsp;Advisors</span>
                            </div>
                            <div class="space-y-1.5 text-[11px]">
                                <div class="px-2 py-1.5 bg-[var(--ink)] text-[var(--cream)] rounded">Dashboard</div>
                                <div class="px-2 py-1.5" style="color: var(--muted-light);">Revenue</div>
                                <div class="px-2 py-1.5" style="color: var(--muted-light);">Customer Insights</div>
                                <div class="px-2 py-1.5" style="color: var(--muted-light);">Receivables</div>
                                <div class="px-2 py-1.5" style="color: var(--muted-light);">Treasury</div>
                                <div class="px-2 py-1.5" style="color: var(--muted-light);">Profit & Loss</div>
                                <div class="px-2 py-1.5" style="color: var(--muted-light);">Inventory</div>
                            </div>
                        </aside>
                        {{-- Main --}}
                        <div class="p-6">
                            <div class="flex items-baseline justify-between mb-5">
                                <div>
                                    <div class="text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">Business Intelligence</div>
                                    <h4 class="font-display text-[22px] leading-tight mt-1">Q1 · 2026 — Operating Snapshot</h4>
                                </div>
                                <div class="font-mono text-[10px] px-2 py-1 border border-[var(--line-light)] rounded">YTD</div>
                            </div>
                            {{-- KPI cards --}}
                            <div class="grid grid-cols-4 gap-3 mb-5">
                                <div class="border border-[var(--line-light)] p-3">
                                    <div class="text-[9px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">Revenue</div>
                                    <div class="font-display text-[20px] mt-1 num">$2.41M</div>
                                    <div class="text-[10px] num mt-1" style="color: var(--good);">+12.4% vs PY</div>
                                </div>
                                <div class="border border-[var(--line-light)] p-3">
                                    <div class="text-[9px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">Gross margin</div>
                                    <div class="font-display text-[20px] mt-1 num">38.2%</div>
                                    <div class="text-[10px] num mt-1" style="color: var(--good);">+180 bps</div>
                                </div>
                                <div class="border border-[var(--line-light)] p-3">
                                    <div class="text-[9px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">AR days</div>
                                    <div class="font-display text-[20px] mt-1 num">31</div>
                                    <div class="text-[10px] num mt-1" style="color: var(--good);">−6 d MoM</div>
                                </div>
                                <div class="border border-[var(--line-light)] p-3">
                                    <div class="text-[9px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">Cash position</div>
                                    <div class="font-display text-[20px] mt-1 num">$1.84M</div>
                                    <div class="text-[10px] num mt-1" style="color: var(--bad);">−$120K WoW</div>
                                </div>
                            </div>
                            {{-- Revenue chart (SVG area) --}}
                            <div class="border border-[var(--line-light)] p-4">
                                <div class="flex items-baseline justify-between mb-2">
                                    <div class="text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-light);">Revenue · trailing 12</div>
                                    <div class="font-mono text-[10px]" style="color: var(--muted-light);">USD ‘000</div>
                                </div>
                                <svg viewBox="0 0 600 140" class="w-full h-[140px]" preserveAspectRatio="none">
                                    <defs>
                                        <linearGradient id="g1" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="0%" stop-color="#C9A961" stop-opacity="0.35"/>
                                            <stop offset="100%" stop-color="#C9A961" stop-opacity="0"/>
                                        </linearGradient>
                                    </defs>
                                    {{-- Grid lines --}}
                                    <line x1="0" x2="600" y1="35" y2="35" stroke="#E8E2D2" stroke-width="0.5"/>
                                    <line x1="0" x2="600" y1="70" y2="70" stroke="#E8E2D2" stroke-width="0.5"/>
                                    <line x1="0" x2="600" y1="105" y2="105" stroke="#E8E2D2" stroke-width="0.5"/>
                                    {{-- Area --}}
                                    <path d="M0,95 L50,82 L100,90 L150,68 L200,72 L250,55 L300,62 L350,40 L400,48 L450,30 L500,38 L550,22 L600,28 L600,140 L0,140 Z" fill="url(#g1)"/>
                                    {{-- Line --}}
                                    <path d="M0,95 L50,82 L100,90 L150,68 L200,72 L250,55 L300,62 L350,40 L400,48 L450,30 L500,38 L550,22 L600,28" fill="none" stroke="#A88840" stroke-width="1.6"/>
                                    {{-- Plan dashed --}}
                                    <path d="M0,90 L50,85 L100,80 L150,75 L200,70 L250,65 L300,60 L350,55 L400,50 L450,45 L500,40 L550,35 L600,30" fill="none" stroke="#0A0908" stroke-width="0.8" stroke-dasharray="3 3" opacity="0.4"/>
                                </svg>
                                <div class="flex items-center gap-4 text-[10px] mt-2" style="color: var(--muted-light);">
                                    <span class="flex items-center gap-1.5"><span class="block w-3 h-px bg-[#A88840]"></span>Actual</span>
                                    <span class="flex items-center gap-1.5"><span class="block w-3 h-px bg-[var(--ink)] opacity-40" style="border-top: 1px dashed currentColor;"></span>Plan</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {{-- Commentary --}}
            <div>
                <div class="font-mono text-[10px] tracking-luxe uppercase font-medium mb-4" style="color: var(--gold);">01 / Business Intelligence</div>
                <h3 class="font-display text-[32px] xl:text-[40px] leading-[1.05] tracking-tight font-light">
                    Every KPI grounded in <span class="italic">your ledger.</span>
                </h3>
                <p class="text-[15px] mt-5 leading-relaxed font-light" style="color: rgba(245,241,234,0.72);">
                    Revenue, margin, AR days, and cash position refreshed live from Odoo —
                    drillable to the invoice. Variance bands compare actual against plan
                    and prior year so the narrative writes itself.
                </p>
                <div class="rule-dark my-7"></div>
                <ul class="space-y-3 text-[13px] font-light" style="color: rgba(245,241,234,0.85);">
                    <li class="flex items-start gap-3"><span class="text-[var(--gold)] mt-1">→</span><span>Trailing-twelve revenue with plan overlay</span></li>
                    <li class="flex items-start gap-3"><span class="text-[var(--gold)] mt-1">→</span><span>Receivables aging surfaced before invoices age past 60 days</span></li>
                    <li class="flex items-start gap-3"><span class="text-[var(--gold)] mt-1">→</span><span>Treasury position with weekly burn signal</span></li>
                </ul>
            </div>
        </div>

        {{-- Dashboard 2 — Profit & Loss / Tax Compliance hybrid --}}
        <div class="grid lg:grid-cols-[1fr_1.6fr] gap-10 lg:gap-16 items-center scroll-reveal">
            {{-- Commentary --}}
            <div class="lg:order-1">
                <div class="font-mono text-[10px] tracking-luxe uppercase font-medium mb-4" style="color: var(--gold);">02 / Reporting & Tax</div>
                <h3 class="font-display text-[32px] xl:text-[40px] leading-[1.05] tracking-tight font-light">
                    Statutory rigour, <span class="italic">at operating speed.</span>
                </h3>
                <p class="text-[15px] mt-5 leading-relaxed font-light" style="color: rgba(245,241,234,0.72);">
                    A management P&L beside a live VAT calendar, both built on the same
                    GL — so every line is reconciled, every filing is traceable, and
                    the audit trail is one click away.
                </p>
                <div class="rule-dark my-7"></div>
                <ul class="space-y-3 text-[13px] font-light" style="color: rgba(245,241,234,0.85);">
                    <li class="flex items-start gap-3"><span class="text-[var(--gold)] mt-1">→</span><span>P&L with sparklines and variance vs budget</span></li>
                    <li class="flex items-start gap-3"><span class="text-[var(--gold)] mt-1">→</span><span>VAT calendar surfaces filings before the deadline</span></li>
                    <li class="flex items-start gap-3"><span class="text-[var(--gold)] mt-1">→</span><span>Invoice-level CGI checks flag exposure on contact</span></li>
                </ul>
            </div>
            {{-- Mockup --}}
            <div class="lg:order-2">
                <div class="mock-shell">
                    <div class="mock-titlebar">
                        <span class="mock-dot"></span><span class="mock-dot"></span><span class="mock-dot"></span>
                        <span class="ml-3 font-mono text-[10px]" style="color: var(--muted-dark);">bms.eliteadvisors.cm-ea.com / profit-loss</span>
                    </div>
                    <div class="p-6 min-h-[420px]">
                        <div class="flex items-baseline justify-between mb-5">
                            <div>
                                <div class="text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted-dark);">Reporting</div>
                                <h4 class="font-display text-[22px] leading-tight mt-1">Profit & Loss · Q1 2026</h4>
                            </div>
                            <div class="flex gap-2 text-[10px] font-mono">
                                <span class="px-2 py-1 border border-[var(--line-dark)] rounded">Q1 26</span>
                                <span class="px-2 py-1 border border-[var(--line-dark)] rounded">vs Q1 25</span>
                            </div>
                        </div>

                        {{-- P&L lines --}}
                        <div class="space-y-px text-[12px]">
                            @php
                                $rows = [
                                    ['Revenue',           '2,410',  '+12.4%', 'good', 'M9,40 L18,28 L27,32 L36,18 L45,22 L54,12'],
                                    ['Cost of sales',    '(1,490)', '+8.1%',  'bad',  'M9,15 L18,22 L27,18 L36,28 L45,32 L54,38'],
                                    ['Gross profit',         '920', '+19.6%', 'good', 'M9,28 L18,22 L27,18 L36,12 L45,8 L54,5',  'em'],
                                    ['Operating expense',   '(540)', '+5.2%',  'bad',  'M9,30 L18,28 L27,32 L36,30 L45,33 L54,35'],
                                    ['EBITDA',                '380', '+34.8%', 'good', 'M9,35 L18,28 L27,22 L36,18 L45,12 L54,6',  'em'],
                                    ['Tax provision',       '(95)', '+11.0%', 'bad',  'M9,28 L18,30 L27,28 L36,32 L45,35 L54,32'],
                                    ['Net income',            '285', '+42.5%', 'good', 'M9,38 L18,32 L27,25 L36,18 L45,10 L54,4',  'em'],
                                ];
                            @endphp
                            @foreach ($rows as $row)
                                @php [$lbl, $val, $delta, $tone] = [$row[0], $row[1], $row[2], $row[3]]; $em = $row[5] ?? null; @endphp
                                <div class="grid grid-cols-[1.6fr_60px_1fr_70px] items-center px-3 py-2 {{ $em ? 'bg-white/[0.04]' : '' }} border-b border-[var(--line-darker)]">
                                    <div class="{{ $em ? 'font-medium text-[var(--cream)]' : '' }}" style="color: {{ $em ? 'var(--cream)' : 'rgba(245,241,234,0.85)' }};">{{ $lbl }}</div>
                                    <div class="font-mono text-right num">
                                        <svg width="60" height="44" viewBox="0 0 63 44" class="inline-block opacity-70">
                                            <path d="{{ $row[4] }}" stroke="{{ $tone === 'good' ? '#8FA77E' : '#C68A6E' }}" stroke-width="1.2" fill="none"/>
                                        </svg>
                                    </div>
                                    <div class="font-mono text-right num">{{ $val }}</div>
                                    <div class="font-mono text-right num" style="color: {{ $tone === 'good' ? 'var(--good)' : 'var(--bad)' }};">{{ $delta }}</div>
                                </div>
                            @endforeach
                        </div>

                        {{-- VAT calendar strip --}}
                        <div class="mt-5 border border-[var(--line-dark)] p-3">
                            <div class="text-[9px] tracking-luxe uppercase font-medium mb-2" style="color: var(--muted-dark);">VAT calendar · next 30 d</div>
                            <div class="grid grid-cols-4 gap-2 text-[10px] font-mono">
                                <div class="px-2 py-2 border border-[var(--line-dark)]"><div style="color: var(--muted-dark);">15 Apr</div><div class="mt-0.5">Monthly VAT</div></div>
                                <div class="px-2 py-2 border border-[var(--line-dark)]"><div style="color: var(--muted-dark);">22 Apr</div><div class="mt-0.5">Withholding</div></div>
                                <div class="px-2 py-2 border border-[var(--gold)] bg-[var(--gold-soft)]"><div style="color: var(--gold);">30 Apr</div><div class="mt-0.5">CIT advance</div></div>
                                <div class="px-2 py-2 border border-[var(--line-dark)]"><div style="color: var(--muted-dark);">12 May</div><div class="mt-0.5">CGI return</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   INTEGRATIONS
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="integrations" class="section-pad">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10">

        <div class="grid lg:grid-cols-[1fr_2fr] gap-12 mb-16 scroll-reveal">
            <div>
                <div class="eyebrow">Integrations</div>
                <h2 class="font-display text-[44px] xl:text-[56px] leading-[0.96] tracking-tight font-light mt-5">
                    Built on top of<br>
                    <span class="italic font-normal text-[var(--gold-deep)]">your stack.</span>
                </h2>
            </div>
            <div class="self-end">
                <p class="text-[17px] leading-relaxed font-light max-w-xl" style="color: var(--muted-light);">
                    BMS sits beside your operating system, not on top of it. We start
                    where you start: today, with Odoo. The roadmap below is sequenced
                    by client demand.
                </p>
            </div>
        </div>

        {{-- Featured: Odoo --}}
        <div class="grid lg:grid-cols-3 gap-8 scroll-reveal">
            <div class="lg:col-span-1 bg-[var(--ink)] text-[var(--cream)] p-10 grain relative overflow-hidden">
                <div class="flex items-center justify-between mb-6">
                    <div class="font-display text-[40px] font-normal">Odoo</div>
                    <span class="pill live"><span class="dot"></span>Live</span>
                </div>
                <p class="text-[14px] font-light leading-relaxed mb-6" style="color: rgba(245,241,234,0.75);">
                    Native bi-directional sync over JSON-RPC. Modules supported: Accounting,
                    Sales, Purchase, Inventory, CRM, HR. Every BMS reading writes back
                    a reconciliation marker.
                </p>
                <div class="rule-dark my-6"></div>
                <ul class="space-y-2 text-[12px]" style="color: rgba(245,241,234,0.85);">
                    <li>· 5-minute refresh cadence</li>
                    <li>· Per-tenant credentials, encrypted at rest</li>
                    <li>· v15, v16, v17 supported</li>
                </ul>
            </div>

            {{-- Coming soon grid --}}
            <div class="lg:col-span-2 grid grid-cols-2 sm:grid-cols-3 gap-px bg-[var(--line-light)]">
                @php
                    $coming = [
                        ['SAP',                 'S/4HANA',           'Q3 2026'],
                        ['Microsoft Dynamics',  '365 Business Central', 'Q3 2026'],
                        ['NetSuite',            'OneWorld',          'Q4 2026'],
                        ['Sage',                'X3 / Intacct',      'Q4 2026'],
                        ['QuickBooks',          'Online',            'Q1 2027'],
                        ['Xero',                'Practice Manager',  'Q1 2027'],
                    ];
                @endphp
                @foreach ($coming as [$name, $detail, $eta])
                    <div class="bg-white p-6 lift">
                        <div class="flex items-center justify-between mb-3">
                            <div class="font-display text-[22px] font-normal">{{ $name }}</div>
                            <span class="pill soon">Coming soon</span>
                        </div>
                        <div class="text-[11px] font-light" style="color: var(--muted-light);">{{ $detail }}</div>
                        <div class="rule-soft my-4"></div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium font-mono" style="color: var(--gold-deep);">ETA {{ $eta }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   SECURITY & TRUST — six pillars
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="security" class="section-pad" style="background: var(--cream-2);">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10">

        <div class="grid lg:grid-cols-[1fr_2fr] gap-12 mb-16 scroll-reveal">
            <div>
                <div class="eyebrow">Security & Trust</div>
                <h2 class="font-display text-[44px] xl:text-[56px] leading-[0.96] tracking-tight font-light mt-5">
                    Engineered for the<br>
                    <span class="italic font-normal text-[var(--gold-deep)]">audit you'd run.</span>
                </h2>
            </div>
            <div class="self-end">
                <p class="text-[17px] leading-relaxed font-light max-w-xl" style="color: var(--muted-light);">
                    BMS holds principal-grade financial data. The platform is built to
                    the controls a Big-Four engagement would expect — and tested against
                    the way your own audit team would actually open the hood.
                </p>
            </div>
        </div>

        @php
            $pillars = [
                ['Per-tenant isolation',
                 'Each engagement is logically isolated end-to-end. URL-scoped routes, middleware-enforced data access, and unique credentials per tenant — no cross-leak by design.',
                 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z'],

                ['Encryption at rest & in transit',
                 'All credentials and secrets are encrypted at rest with AES-256. Every connection uses TLS 1.3. Backups are encrypted and stored in geographically-isolated regions.',
                 'M12 15a2 2 0 100-4 2 2 0 000 4zm6-7V6a6 6 0 10-12 0v2a4 4 0 00-4 4v8a4 4 0 004 4h12a4 4 0 004-4v-8a4 4 0 00-4-4z'],

                ['Audit-grade logging',
                 'Every administrative action — sign-in, module toggle, credential change, tenant impersonation — is recorded against an immutable audit log keyed to the actor.',
                 'M9 12l2 2 4-4M9 5h6a2 2 0 012 2v12a2 2 0 01-2 2H9a2 2 0 01-2-2V7a2 2 0 012-2z'],

                ['Role-based access control',
                 'Six pre-defined roles from Super Admin to Read-only Auditor. Module visibility, settings access, and impersonation are gated to the role and logged on use.',
                 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],

                ['Credential hygiene',
                 'Integration credentials (Odoo API keys, AI provider keys) are stored encrypted, never displayed after capture, and rotated independently of user passwords.',
                 'M15 7a2 2 0 11-4 0 2 2 0 014 0zM3 12l9-9 9 9-9 9-9-9z'],

                ['Compliance posture',
                 'Aligned to ISO 27001 control objectives and SOC 2 Trust Services Criteria. Data-processing agreements available for engagements requiring formal DPA terms.',
                 'M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ];
        @endphp

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-px bg-[var(--line-mid)] scroll-reveal">
            @foreach ($pillars as [$title, $body, $icon])
                <div class="bg-[var(--cream-2)] p-8 lg:p-10 lg:min-h-[260px]">
                    <div class="text-[var(--gold-deep)] mb-6">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
                        </svg>
                    </div>
                    <h3 class="font-display text-[20px] leading-tight font-normal">{{ $title }}</h3>
                    <p class="text-[13px] mt-3 leading-relaxed font-light" style="color: var(--muted-light);">{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   NEWSROOM — Tier 2 content (regulatory + events + recognition)
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="newsroom" class="section-pad">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10">

        <div class="grid lg:grid-cols-[1fr_2fr] gap-12 mb-16 scroll-reveal">
            <div>
                <div class="eyebrow">Newsroom</div>
                <h2 class="font-display text-[44px] xl:text-[56px] leading-[0.96] tracking-tight font-light mt-5">
                    What's moving<br>
                    <span class="italic font-normal text-[var(--gold-deep)]">around our clients.</span>
                </h2>
            </div>
            <div class="self-end">
                <p class="text-[17px] leading-relaxed font-light max-w-xl" style="color: var(--muted-light);">
                    Regulatory changes, events worth attending, and the recognitions
                    we've earned alongside the firms we serve.
                </p>
            </div>
        </div>

        {{-- ─────────── Regulatory pulse ─────────── --}}
        <div class="mb-20 scroll-reveal">
            <div class="flex items-baseline justify-between mb-7">
                <h3 class="font-display text-[26px] font-normal">Regulatory pulse</h3>
                <a href="#" class="nav-link text-[12px] tracking-luxe uppercase font-medium">All updates →</a>
            </div>
            <div class="grid lg:grid-cols-3 gap-px bg-[var(--line-light)]">
                @php
                    $regs = [
                        ['CEMAC · VAT',     'Quarterly VAT calendar — Q2 2026 deadlines',
                         'Updated CEMAC VAT calendar with corrected filing windows for monthly and quarterly regimes. Action required: confirm regime classification by 30 April.',
                         '04 Apr 2026'],
                        ['OHADA · AUDCIF',  'AUDCIF disclosure update for management reporting',
                         'New presentation requirements for management commentary alongside statutory accounts. Affects entities subject to AUDCIF Articles 8–11.',
                         '28 Mar 2026'],
                        ['IFRS · IFRS 18',  'IFRS 18 effective date approaches — Jan 2027',
                         'IFRS 18 reshapes the income statement into three categories. Early-adopter firms should begin restating comparative periods this quarter.',
                         '21 Mar 2026'],
                    ];
                @endphp
                @foreach ($regs as [$tag, $title, $body, $date])
                    <article class="bg-[#FFFEF9] p-8 lg:p-10 lg:min-h-[280px] flex flex-col lift">
                        <div class="flex items-baseline justify-between mb-4">
                            <span class="text-[10px] tracking-luxe uppercase font-medium font-mono" style="color: var(--gold-deep);">{{ $tag }}</span>
                            <span class="text-[10px] font-mono" style="color: var(--muted-light);">{{ $date }}</span>
                        </div>
                        <h4 class="font-display text-[20px] leading-tight font-normal">{{ $title }}</h4>
                        <p class="text-[13px] mt-4 leading-relaxed font-light" style="color: var(--muted-light);">{{ $body }}</p>
                        <div class="mt-auto pt-6">
                            <a href="#" class="text-[11px] tracking-luxe uppercase font-medium" style="color: var(--ink);">Read briefing →</a>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>

        {{-- ─────────── Events & roundtables ─────────── --}}
        <div class="mb-20 scroll-reveal">
            <div class="flex items-baseline justify-between mb-7">
                <h3 class="font-display text-[26px] font-normal">Events & roundtables</h3>
                <a href="#" class="nav-link text-[12px] tracking-luxe uppercase font-medium">Full calendar →</a>
            </div>
            <div class="border-t border-[var(--line-light)]">
                @php
                    $events = [
                        ['30 Apr 2026', 'CFO Breakfast · Douala',         'Closed-door roundtable on operating discipline in volatile cycles. By invitation.', 'Douala · Cameroon', 'Open'],
                        ['15 May 2026', 'Webinar — Industry KPIs by sector', 'Live walk-through of BMS industry templates. 60 minutes incl. Q&A.', 'Online · 14:00 GMT', 'Register'],
                        ['12 Jun 2026', 'Africa Finance & Strategy Summit', 'Elite Advisors hosts a panel on operating intelligence for mid-market firms.', 'Kigali · Rwanda', 'Limited'],
                        ['18 Sep 2026', 'BMS User Conference 2026',       'Annual conference for principals operating on BMS. Workshops, roadmap, networking.', 'Yaoundé · Cameroon', 'Save the date'],
                    ];
                @endphp
                @foreach ($events as [$date, $title, $body, $loc, $cta])
                    <a href="#" class="grid grid-cols-1 lg:grid-cols-[160px_1fr_180px_120px] gap-6 px-3 py-7 border-b border-[var(--line-light)] hover:bg-white/60 transition group">
                        <div class="font-mono text-[11px] tracking-luxe uppercase font-medium" style="color: var(--gold-deep);">{{ $date }}</div>
                        <div>
                            <h4 class="font-display text-[20px] leading-tight font-normal group-hover:text-[var(--ink)]">{{ $title }}</h4>
                            <p class="text-[13px] mt-2 font-light" style="color: var(--muted-light);">{{ $body }}</p>
                        </div>
                        <div class="text-[12px] font-light flex items-center" style="color: var(--muted-light);">{{ $loc }}</div>
                        <div class="flex items-center justify-start lg:justify-end">
                            <span class="text-[11px] tracking-luxe uppercase font-medium" style="color: var(--ink);">{{ $cta }} →</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- ─────────── Recognition & memberships ─────────── --}}
        <div class="scroll-reveal">
            <div class="flex items-baseline justify-between mb-7">
                <h3 class="font-display text-[26px] font-normal">Recognition & memberships</h3>
            </div>
            @php
                $recognition = [
                    ['ONECCA',     'Member firm — Ordre National des Experts Comptables du Cameroun'],
                    ['OHADA',      'Compliant with OHADA AUDCIF reporting framework'],
                    ['IFAC',       'Aligned to IFAC International Standards on Auditing'],
                    ['CEMAC',      'Filing-ready for CEMAC VAT, withholding & CIT regimes'],
                    ['ISO 27001',  'Controls aligned to ISO 27001 information security'],
                    ['SOC 2',      'Trust Services Criteria mapped to AICPA SOC 2 framework'],
                ];
            @endphp
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-px bg-[var(--line-light)]">
                @foreach ($recognition as [$badge, $body])
                    <div class="bg-[#FFFEF9] p-7 lg:p-8 flex items-start gap-5">
                        <div class="font-display text-[24px] leading-none font-normal text-[var(--gold-deep)] shrink-0">{{ $badge }}</div>
                        <p class="text-[13px] leading-relaxed font-light" style="color: var(--muted-light);">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════════════════════════════════ --}}
<footer class="grain pt-20 pb-10 relative overflow-hidden" style="background: var(--ink); color: var(--cream);">
    <div class="max-w-[1400px] mx-auto px-6 lg:px-10 relative">

        <div class="grid lg:grid-cols-[1.4fr_1fr_1fr_1fr] gap-10 lg:gap-16 mb-16">

            {{-- Brand block --}}
            <div>
                <div class="flex items-center gap-3 mb-5">
                    <svg width="44" height="32" viewBox="0 0 44 32" fill="currentColor" class="text-[var(--cream)]">
                        <rect x="0" y="2" width="44" height="6.5" rx="0.5"/>
                        <rect x="0" y="12.75" width="44" height="6.5" rx="0.5"/>
                        <rect x="0" y="23.5" width="44" height="6.5" rx="0.5"/>
                    </svg>
                    <div class="leading-tight">
                        <div class="text-[12px] tracking-wordmark uppercase font-medium">Elite&nbsp;Advisors</div>
                        <div class="text-[8.5px] tracking-bms uppercase font-medium text-[var(--gold)] mt-0.5">Business&nbsp;Management&nbsp;Suite&nbsp;·&nbsp;BMS</div>
                    </div>
                </div>
                <p class="text-[14px] leading-relaxed font-light max-w-sm" style="color: rgba(245,241,234,0.65);">
                    A principal-grade business management suite — engineered for the
                    rigour your firm deserves and the cadence at which you actually run.
                </p>
                <div class="mt-7 pt-6 border-t border-[var(--line-dark)]">
                    <a href="https://www.eliteadvisors.cm-ea.com" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 text-[14px] font-medium hover:text-[var(--gold)] transition">
                        www.eliteadvisors.cm-ea.com
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3h7v7M10 14L21 3M21 14v7H3V3h7"/></svg>
                    </a>
                </div>
            </div>

            {{-- Footer columns --}}
            <div>
                <div class="text-[10px] tracking-luxe uppercase font-medium mb-5" style="color: var(--gold);">Suite</div>
                <ul class="space-y-3 text-[13px] font-light" style="color: rgba(245,241,234,0.75);">
                    <li><a href="#capabilities" class="hover:text-[var(--cream)] transition">Business Intelligence</a></li>
                    <li><a href="#capabilities" class="hover:text-[var(--cream)] transition">Financial Reporting</a></li>
                    <li><a href="#capabilities" class="hover:text-[var(--cream)] transition">Tax Compliance</a></li>
                    <li><a href="#capabilities" class="hover:text-[var(--cream)] transition">Financial Planning</a></li>
                </ul>
            </div>

            <div>
                <div class="text-[10px] tracking-luxe uppercase font-medium mb-5" style="color: var(--gold);">Platform</div>
                <ul class="space-y-3 text-[13px] font-light" style="color: rgba(245,241,234,0.75);">
                    <li><a href="#integrations" class="hover:text-[var(--cream)] transition">Integrations</a></li>
                    <li><a href="#security" class="hover:text-[var(--cream)] transition">Security</a></li>
                    <li><a href="#dashboards" class="hover:text-[var(--cream)] transition">Dashboards</a></li>
                    <li><a href="#newsroom" class="hover:text-[var(--cream)] transition">Newsroom</a></li>
                </ul>
            </div>

            <div>
                <div class="text-[10px] tracking-luxe uppercase font-medium mb-5" style="color: var(--gold);">Engage</div>
                <ul class="space-y-3 text-[13px] font-light" style="color: rgba(245,241,234,0.75);">
                    <li><a href="#signin" class="hover:text-[var(--cream)] transition">Sign in</a></li>
                    <li><a href="https://www.eliteadvisors.cm-ea.com" class="hover:text-[var(--cream)] transition">Contact</a></li>
                    <li><a href="#newsroom" class="hover:text-[var(--cream)] transition">Events</a></li>
                </ul>
            </div>
        </div>

        <div class="rule-dark"></div>

        <div class="pt-7 flex flex-col md:flex-row md:items-center md:justify-between gap-4 text-[10px] tracking-luxe uppercase font-medium" style="color: rgba(245,241,234,0.45);">
            <div>© 2026 Elite Advisors · Business Management Suite</div>
            <div class="flex items-center gap-6">
                <span>Confidential — authorized use only</span>
                <span class="font-mono">v1.0</span>
            </div>
        </div>
    </div>
</footer>

{{-- ═══════════════════════════════════════════════════════════════════════════
   JS — sticky nav blur, scroll reveals
═══════════════════════════════════════════════════════════════════════════ --}}
<script>
    // Sticky nav backdrop blur on scroll
    (function() {
        const nav = document.getElementById('navbar');
        const onScroll = () => {
            if (window.scrollY > 24) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    })();

    // Intersection observer for scroll-reveal
    (function() {
        const items = document.querySelectorAll('.scroll-reveal');
        if (!('IntersectionObserver' in window)) {
            items.forEach(el => el.classList.add('is-visible'));
            return;
        }
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    e.target.classList.add('is-visible');
                    obs.unobserve(e.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        items.forEach(el => obs.observe(el));
    })();
</script>

</body>
</html>
