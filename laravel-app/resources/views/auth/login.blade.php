<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · {{ config('bi.brand') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>

    {{-- Editorial typography pairing: Fraunces (display serif) + DM Sans (body) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400;1,9..144,500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink: #0F0E0C;
            --ink-soft: #1A1812;
            --cream: #F5F1EA;
            --cream-soft: #EDE7DD;
            --champagne: #C9A961;
            --champagne-deep: #A88840;
            --muted: #A7A39B;
            --line-dark: #2A2823;
            --line-light: rgba(15,14,12,0.10);
            --warn: #C68A6E;
        }

        *, *::before, *::after {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        html, body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--cream);
            color: var(--ink);
        }

        .font-display {
            font-family: 'Fraunces', 'Times New Roman', serif;
            font-feature-settings: "ss01" 1, "ss02" 1, "ss03" 1;
        }

        .tracking-luxe { letter-spacing: 0.20em; }
        .tracking-wordmark { letter-spacing: 0.36em; }

        .num {
            font-feature-settings: "tnum" 1, "lnum" 1, "ss01" 1;
        }

        /* Subtle film-grain overlay on the dark editorial pane */
        .grain::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.07;
            mix-blend-mode: overlay;
            background-image: url("data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        }

        /* Hairline horizontal rule */
        .rule { height: 1px; background: var(--line-dark); }
        .rule-light { height: 1px; background: var(--line-light); }

        /* ── Reveal animations (staggered on load) ─────────────── */
        @keyframes rise {
            0%   { opacity: 0; transform: translateY(14px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes glide {
            0%   { opacity: 0; transform: translateX(-10px); }
            100% { opacity: 1; transform: translateX(0); }
        }
        .reveal       { animation: rise 1.1s cubic-bezier(0.2,0.7,0.2,1) both; }
        .reveal-glide { animation: glide 1s cubic-bezier(0.2,0.7,0.2,1) both; }

        /* ── Form fields: minimal, hairline-only ───────────────── */
        .field {
            background: transparent;
            border: 0;
            border-bottom: 1px solid #C0BBAF;
            border-radius: 0;
            padding: 14px 0 12px;
            font-family: 'DM Sans', sans-serif;
            font-size: 16px;
            color: var(--ink);
            width: 100%;
            transition: border-color 0.4s ease, color 0.3s;
        }
        .field:focus {
            outline: none;
            border-bottom-color: var(--ink);
        }
        .field::placeholder {
            color: #B5AFA2;
            font-weight: 300;
        }
        .field-row { position: relative; }
        .field-row::after {
            content: '';
            position: absolute;
            left: 0; right: 100%;
            bottom: 0; height: 1px;
            background: var(--champagne);
            transition: right 0.6s cubic-bezier(0.2,0.7,0.2,1);
        }
        .field-row:focus-within::after { right: 0; }

        /* ── Primary submit button ─────────────────────────────── */
        .btn-primary {
            background: var(--ink);
            color: var(--cream);
            padding: 18px 24px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            letter-spacing: 0.20em;
            text-transform: uppercase;
            font-weight: 500;
            transition: background 0.35s ease, letter-spacing 0.35s ease;
            position: relative;
            border: 0;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: var(--ink-soft);
            letter-spacing: 0.24em;
        }
        .btn-primary .arrow {
            display: inline-block;
            transition: transform 0.4s cubic-bezier(0.2,0.7,0.2,1);
            margin-left: 4px;
        }
        .btn-primary:hover .arrow { transform: translateX(6px); }

        /* ── Live ticker (continuous marquee) ──────────────────── */
        @keyframes tick {
            0%   { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-50%, 0, 0); }
        }
        .ticker-track {
            animation: tick 50s linear infinite;
            will-change: transform;
        }

        /* Pulsing live dot */
        @keyframes pulse-live {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.45; transform: scale(0.85); }
        }
        .pulse-live { animation: pulse-live 2.2s ease-in-out infinite; }

        /* Keep the page steady on small viewports */
        body { overflow-x: hidden; }

        /* Drop-cap / flourish styling for the headline */
        .headline em {
            font-style: italic;
            font-weight: 400;
            color: var(--champagne);
        }
    </style>
</head>
<body class="h-full">

<main class="grid lg:grid-cols-[1.15fr_1fr] min-h-screen">

    {{-- ═══════════════════ LEFT — EDITORIAL PANE ═══════════════════ --}}
    <section class="relative grain hidden lg:flex flex-col p-12 xl:p-16 2xl:p-20"
             style="background: var(--ink); color: var(--cream);">

        {{-- Top: brand mark + meta --}}
        <header class="flex items-start justify-between reveal" style="animation-delay: 0.05s;">
            <a href="/" class="block group">
                {{-- Three-bar Elite Advisors mark, recreated in inline SVG --}}
                <svg width="60" height="42" viewBox="0 0 60 42" fill="currentColor" class="text-[var(--cream)] block">
                    <rect x="0" y="0"  width="60" height="6" rx="0.5"/>
                    <rect x="0" y="11" width="60" height="6" rx="0.5"/>
                    <rect x="0" y="22" width="60" height="6" rx="0.5"/>
                </svg>
                <div class="mt-3.5 text-[11px] tracking-wordmark uppercase font-medium text-[var(--cream)]">
                    Elite&nbsp;Advisors
                </div>
            </a>

            <div class="text-right text-[10px] tracking-luxe uppercase font-medium" style="color: var(--muted);">
                <div>Est. MMXXIV</div>
                <div class="mt-1 flex items-center gap-1.5 justify-end">
                    <span class="block w-1 h-1 rounded-full bg-[var(--champagne)] pulse-live"></span>
                    Q1 · MMXXVI
                </div>
            </div>
        </header>

        {{-- Centerpiece: editorial headline --}}
        <div class="flex-1 flex flex-col justify-center max-w-2xl">

            <div class="text-[10px] tracking-luxe uppercase font-medium reveal flex items-center gap-3"
                 style="color: var(--champagne); animation-delay: 0.20s;">
                <span class="block w-8 h-px bg-[var(--champagne)]"></span>
                Engagement console
            </div>

            <h1 class="font-display text-[56px] sm:text-[64px] xl:text-[80px] 2xl:text-[88px]
                       leading-[0.95] tracking-tight font-light mt-7 headline reveal"
                style="animation-delay: 0.32s;">
                Intelligence,<br>
                <em>in confidence.</em>
            </h1>

            <p class="text-base xl:text-lg leading-[1.6] mt-9 max-w-lg font-light reveal"
               style="color: rgba(245,241,234,0.72); animation-delay: 0.46s;">
                A management console for principals who measure progress in basis points
                — not impressions. Real-time operating intelligence, audit-grade reporting,
                and advisory disciplines reserved, until now, for the largest engagements.
            </p>

            {{-- Stats — three figures in editorial proportion --}}
            <div class="mt-14 reveal" style="animation-delay: 0.60s;">
                <div class="rule mb-7"></div>
                <div class="grid grid-cols-3 gap-8">
                    <div>
                        <div class="font-display text-[34px] xl:text-[40px] tracking-tight font-light leading-none num">
                            $24.7<span style="color: var(--champagne);">B</span>
                        </div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium mt-3" style="color: var(--muted);">
                            Capital under<br>advisory
                        </div>
                    </div>
                    <div>
                        <div class="font-display text-[34px] xl:text-[40px] tracking-tight font-light leading-none num">
                            1,847
                        </div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium mt-3" style="color: var(--muted);">
                            Active<br>engagements
                        </div>
                    </div>
                    <div>
                        <div class="font-display text-[34px] xl:text-[40px] tracking-tight font-light leading-none num">
                            98.7<span style="color: var(--champagne);">%</span>
                        </div>
                        <div class="text-[10px] tracking-luxe uppercase font-medium mt-3" style="color: var(--muted);">
                            Audit-grade<br>fidelity
                        </div>
                    </div>
                </div>
                <div class="rule mt-7"></div>
            </div>
        </div>

        {{-- Foot: live cohort ticker --}}
        <footer class="reveal" style="animation-delay: 0.78s;">
            <div class="flex items-center gap-2.5 text-[10px] tracking-luxe uppercase font-medium mb-3"
                 style="color: var(--muted);">
                <span class="block w-1.5 h-1.5 rounded-full bg-[var(--champagne)] pulse-live"></span>
                Live · Aggregate cohort signal
            </div>

            <div class="overflow-hidden border-y" style="border-color: var(--line-dark);">
                <div class="ticker-track flex gap-14 whitespace-nowrap py-4">
                    @php
                        $ticks = [
                            ['REV',       '+12.4%',  'pos'],
                            ['MARGIN',    '24.1%',   ''   ],
                            ['AR · DAYS', '31',      ''   ],
                            ['RUNWAY',    '18.4 MO', ''   ],
                            ['CASH',      '$2.1M',   ''   ],
                            ['DSO',       '42 D',    'neg'],
                            ['ROE',       '14.8%',   'pos'],
                            ['INV TURN',  '6.2×',    ''   ],
                            ['NPS',       '+62',     'pos'],
                            ['BURN',      '$84K',    ''   ],
                            ['EBITDA',    '+18.2%',  'pos'],
                            ['CAC : LTV', '1 : 4.7', ''   ],
                        ];
                    @endphp
                    @for ($i = 0; $i < 2; $i++)
                        @foreach ($ticks as [$label, $value, $tone])
                            <span class="flex items-center gap-2.5 num text-[12px]">
                                <span class="tracking-luxe uppercase text-[10px] font-medium"
                                      style="color: var(--muted);">{{ $label }}</span>
                                <span style="color:
                                    @if($tone==='pos') var(--champagne)
                                    @elseif($tone==='neg') var(--warn)
                                    @else var(--cream)
                                    @endif;">{{ $value }}</span>
                            </span>
                        @endforeach
                    @endfor
                </div>
            </div>
        </footer>
    </section>

    {{-- ═══════════════════ RIGHT — LOGIN PANE ═══════════════════ --}}
    <section class="relative flex flex-col p-8 sm:p-12 lg:p-14 xl:p-20"
             style="background: var(--cream);">

        {{-- Mobile-only: condensed brand --}}
        <header class="lg:hidden mb-12">
            <svg width="50" height="35" viewBox="0 0 60 42" fill="currentColor" class="block" style="color: var(--ink);">
                <rect x="0" y="0"  width="60" height="6"/>
                <rect x="0" y="11" width="60" height="6"/>
                <rect x="0" y="22" width="60" height="6"/>
            </svg>
            <div class="mt-3 text-[10px] tracking-wordmark uppercase font-medium" style="color: var(--ink);">
                Elite&nbsp;Advisors
            </div>
        </header>

        {{-- Top right meta (desktop) --}}
        <div class="hidden lg:flex justify-end text-[10px] tracking-luxe uppercase font-medium"
             style="color: rgba(15,14,12,0.40);">
            <span>Engagement Console · v1.0</span>
        </div>

        {{-- Form column --}}
        <div class="flex-1 flex flex-col justify-center max-w-md w-full mx-auto">

            <div class="text-[10px] tracking-luxe uppercase font-medium reveal-glide flex items-center gap-3"
                 style="color: var(--champagne-deep);">
                <span class="block w-8 h-px" style="background: var(--champagne-deep);"></span>
                Sign in
            </div>

            <h2 class="font-display text-[44px] sm:text-[52px] xl:text-[60px] leading-[0.95] tracking-tight font-light mt-4 reveal-glide"
                style="animation-delay: 0.10s;">
                Welcome <span class="italic">back.</span>
            </h2>

            <p class="text-[15px] mt-5 leading-relaxed font-light reveal-glide"
               style="color: rgba(15,14,12,0.62); animation-delay: 0.20s;">
                Continue to your engagement console. Authorized access only —
                credentials are issued per principal.
            </p>

            @if (session('flash'))
              <div class="mt-7 px-4 py-3 text-[12px] leading-relaxed reveal-glide"
                   style="border: 1px solid rgba(198,138,110,0.35); background: #F8E8DD; color: #7A3D1F; animation-delay: 0.25s;">
                  {{ session('flash.msg') }}
              </div>
            @endif

            <form method="POST"
                  action="{{ $tenantSlug ? '/t/'.$tenantSlug.'/login' : '/login' }}"
                  class="mt-10 space-y-7">
                @csrf

                <div class="reveal-glide" style="animation-delay: 0.30s;">
                    <label class="block text-[10px] tracking-luxe uppercase font-medium mb-2"
                           style="color: rgba(15,14,12,0.50);">
                        Username
                    </label>
                    <div class="field-row">
                        <input name="username"
                               autofocus required
                               autocomplete="username"
                               class="field"
                               placeholder="principal@company">
                    </div>
                </div>

                <div class="reveal-glide" style="animation-delay: 0.40s;">
                    <div class="flex items-baseline justify-between mb-2">
                        <label class="block text-[10px] tracking-luxe uppercase font-medium"
                               style="color: rgba(15,14,12,0.50);">
                            Password
                        </label>
                        <a href="mailto:concierge@elite-advisors.local"
                           class="text-[10px] tracking-luxe uppercase font-medium transition"
                           style="color: rgba(15,14,12,0.40);"
                           onmouseover="this.style.color='var(--champagne-deep)'"
                           onmouseout="this.style.color='rgba(15,14,12,0.40)'">
                            Concierge
                        </a>
                    </div>
                    <div class="field-row">
                        <input name="password"
                               type="password"
                               required
                               autocomplete="current-password"
                               class="field"
                               placeholder="••••••••••">
                    </div>
                </div>

                <div class="pt-5 reveal-glide" style="animation-delay: 0.52s;">
                    <button type="submit"
                            class="btn-primary w-full inline-flex items-center justify-center">
                        <span>Sign in</span>
                        <span class="arrow">→</span>
                    </button>
                </div>
            </form>

            {{-- Trust micro-copy --}}
            <div class="mt-12 pt-7 reveal-glide" style="animation-delay: 0.65s;">
                <div class="rule-light mb-7"></div>
                <div class="flex items-start gap-3 text-[11px] leading-[1.65] font-light"
                     style="color: rgba(15,14,12,0.55);">
                    <svg class="w-3.5 h-3.5 mt-0.5 shrink-0"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                         style="color: var(--champagne-deep);">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                    <span>
                        Sessions are encrypted, scoped to your engagement, and audit-logged
                        in line with internal controls.
                    </span>
                </div>
            </div>
        </div>

        {{-- Page footer --}}
        <footer class="text-[10px] tracking-luxe uppercase font-medium pt-10 flex items-center justify-between reveal-glide"
                style="color: rgba(15,14,12,0.40); animation-delay: 0.80s;">
            <span>© MMXXVI Elite Advisors</span>
            <span class="hidden sm:block">Confidential · Authorized use only</span>
        </footer>

    </section>

</main>

</body>
</html>
