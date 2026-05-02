<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Elite Advisors · Business Management Suite</title>
    <meta name="description" content="Operating intelligence, audit-grade reporting, and tax compliance — engineered as a single principal-grade console. Built on top of Odoo.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Elite Advisors design system: Hanken Grotesk (UI) · Newsreader (display serif) · JetBrains Mono (numerals) --}}
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@300;400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,300;1,6..72,400;1,6..72,500&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        /* ============================================================
           ELITE Advisors — Design System
           Embedded inline from ui_kits/marketing/{palettes,marketing}.css
           Default palette: WARM (parchment + bronze + forest)
           Palette switcher (TweaksPanel) at bottom-right swaps tokens.
           ============================================================ */

        /* ----------- TYPE & TOKEN BASE (colors_and_type.css) ----------- */
        :root {
            --font-sans:  'Hanken Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-serif: 'Newsreader', 'Times New Roman', Georgia, serif;
            --font-mono:  'JetBrains Mono', ui-monospace, monospace;

            --fs-display-xl: clamp(56px, 7.5vw, 96px);
            --fs-display-l:  clamp(48px, 6.5vw, 80px);
            --fs-display-m:  clamp(40px, 5vw, 56px);
            --fs-h1:    40px;
            --fs-h2:    32px;
            --fs-h3:    24px;
            --fs-h4:    20px;
            --fs-body-l:  18px;
            --fs-body:    16px;
            --fs-body-s:  14px;
            --fs-caption: 13px;
            --fs-micro:   11px;

            --lh-display: 1.05;
            --lh-tight:   1.18;
            --lh-snug:    1.32;
            --lh-body:    1.55;
            --lh-loose:   1.7;

            --fw-light: 300; --fw-regular: 400; --fw-medium: 500; --fw-semi: 600; --fw-bold: 700;
            --tracking-display: -0.02em;
            --tracking-tight:   -0.01em;
            --tracking-eyebrow: 0.18em;

            --space-1:4px; --space-2:8px; --space-3:12px; --space-4:16px; --space-5:20px;
            --space-6:24px; --space-8:32px; --space-10:40px; --space-12:48px; --space-16:64px;
            --space-20:80px; --space-24:96px; --space-32:128px;

            --radius-sm:2px; --radius-md:4px; --radius-lg:8px; --radius-xl:12px; --radius-pill:999px;

            --shadow-xs: 0 1px 2px rgba(31,27,23,0.04);
            --shadow-sm: 0 1px 2px rgba(31,27,23,0.06), 0 1px 1px rgba(31,27,23,0.04);
            --shadow-md: 0 4px 12px rgba(31,27,23,0.06), 0 2px 4px rgba(31,27,23,0.04);
            --shadow-lg: 0 12px 32px rgba(31,27,23,0.08), 0 4px 8px rgba(31,27,23,0.04);
            --shadow-xl: 0 24px 64px rgba(31,27,23,0.12), 0 8px 16px rgba(31,27,23,0.06);

            --ease-standard: cubic-bezier(0.4, 0, 0.2, 1);
            --ease-emphasis: cubic-bezier(0.2, 0.8, 0.2, 1);
            --dur-quick: 160ms; --dur-base: 240ms; --dur-slow: 400ms;

            --container-default: 1200px;
            --container-wide:    1440px;
        }

        /* ----------- PALETTES (palettes.css) ----------- */

        /* WARM — default */
        [data-palette="warm"] {
            --bg-page:      #FBF8F1; --bg-surface:   #F5F0E4; --bg-surface-2: #EDE5D2; --bg-sunken:    #E2D6BD;
            --fg-primary:   #14110F; --fg-secondary: #4A4239; --fg-tertiary:  #6B6157; --fg-muted:     #948A7E;
            --ink-100:#ECE6DA; --ink-200:#D8D0C4; --ink-300:#B8AFA3; --ink-500:#6B6157; --ink-700:#2E2823; --ink-900:#14110F;
            --parchment-50:#FBF8F1; --parchment-100:#F5F0E4; --parchment-200:#EDE5D2; --parchment-300:#E2D6BD;
            --accent:#B07F3B; --accent-hover:#8E6228; --accent-soft:#F8EFE2; --accent-fg:#4F3614;
            --bronze-50:#F8EFE2; --bronze-400:#B07F3B; --bronze-500:#8E6228; --bronze-700:#4F3614;
            --secondary:#3E5A44; --forest-50:#ECF1EC; --forest-500:#3E5A44; --forest-700:#25382A;
            --good:#3E5A44; --bad:#B26A53;
        }

        /* MONO — pure black on pure white */
        [data-palette="mono"] {
            --bg-page:#FFFFFF; --bg-surface:#FAFAFA; --bg-surface-2:#F2F2F2; --bg-sunken:#E8E8E8;
            --fg-primary:#000000; --fg-secondary:#2A2A2A; --fg-tertiary:#5C5C5C; --fg-muted:#8A8A8A;
            --ink-100:#F2F2F2; --ink-200:#E5E5E5; --ink-300:#C9C9C9; --ink-500:#5C5C5C; --ink-700:#1F1F1F; --ink-900:#000000;
            --parchment-50:#FFFFFF; --parchment-100:#FAFAFA; --parchment-200:#F2F2F2; --parchment-300:#E8E8E8;
            --accent:#000000; --accent-hover:#2A2A2A; --accent-soft:#F2F2F2; --accent-fg:#000000;
            --bronze-50:#F2F2F2; --bronze-400:#000000; --bronze-500:#2A2A2A; --bronze-700:#000000;
            --secondary:#2A2A2A; --forest-50:#F2F2F2; --forest-500:#2A2A2A; --forest-700:#000000;
            --good:#2A2A2A; --bad:#5C5C5C;
        }

        /* NEWSPRINT — soft warm white, near-black ink */
        [data-palette="newsprint"] {
            --bg-page:#FBFAF7; --bg-surface:#FFFFFF; --bg-surface-2:#F2F1ED; --bg-sunken:#E6E5E0;
            --fg-primary:#0E0E0C; --fg-secondary:#2E2E2B; --fg-tertiary:#5F5F5A; --fg-muted:#8C8C86;
            --ink-100:#F2F1ED; --ink-200:#E6E5E0; --ink-300:#C9C8C2; --ink-500:#5F5F5A; --ink-700:#1F1F1C; --ink-900:#0E0E0C;
            --parchment-50:#FBFAF7; --parchment-100:#FFFFFF; --parchment-200:#F2F1ED; --parchment-300:#E6E5E0;
            --accent:#0E0E0C; --accent-hover:#2E2E2B; --accent-soft:#F2F1ED; --accent-fg:#0E0E0C;
            --bronze-50:#F2F1ED; --bronze-400:#0E0E0C; --bronze-500:#2E2E2B; --bronze-700:#0E0E0C;
            --secondary:#2E2E2B; --forest-50:#F2F1ED; --forest-500:#2E2E2B; --forest-700:#0E0E0C;
            --good:#2E2E2B; --bad:#5F5F5A;
        }

        /* GRAPHITE — light grey page, charcoal ink */
        [data-palette="graphite"] {
            --bg-page:#F4F4F4; --bg-surface:#FFFFFF; --bg-surface-2:#EBEBEB; --bg-sunken:#DEDEDE;
            --fg-primary:#16161A; --fg-secondary:#3B3B3F; --fg-tertiary:#6B6B6F; --fg-muted:#95959A;
            --ink-100:#EBEBEB; --ink-200:#DEDEDE; --ink-300:#C2C2C2; --ink-500:#6B6B6F; --ink-700:#25252A; --ink-900:#16161A;
            --parchment-50:#F4F4F4; --parchment-100:#FFFFFF; --parchment-200:#EBEBEB; --parchment-300:#DEDEDE;
            --accent:#16161A; --accent-hover:#3B3B3F; --accent-soft:#EBEBEB; --accent-fg:#16161A;
            --bronze-50:#EBEBEB; --bronze-400:#16161A; --bronze-500:#3B3B3F; --bronze-700:#16161A;
            --secondary:#3B3B3F; --forest-50:#EBEBEB; --forest-500:#3B3B3F; --forest-700:#16161A;
            --good:#3B3B3F; --bad:#6B6B6F;
        }

        /* INVERSE — black page, white ink */
        [data-palette="inverse"] {
            --bg-page:#0A0A0A; --bg-surface:#141414; --bg-surface-2:#1F1F1F; --bg-sunken:#2A2A2A;
            --fg-primary:#FFFFFF; --fg-secondary:#D6D6D6; --fg-tertiary:#9A9A9A; --fg-muted:#6B6B6B;
            --ink-100:#1F1F1F; --ink-200:#2A2A2A; --ink-300:#3D3D3D; --ink-500:#9A9A9A; --ink-700:#D6D6D6; --ink-900:#FFFFFF;
            --parchment-50:#0A0A0A; --parchment-100:#141414; --parchment-200:#1F1F1F; --parchment-300:#2A2A2A;
            --accent:#FFFFFF; --accent-hover:#D6D6D6; --accent-soft:#1F1F1F; --accent-fg:#FFFFFF;
            --bronze-50:#1F1F1F; --bronze-400:#FFFFFF; --bronze-500:#D6D6D6; --bronze-700:#FFFFFF;
            --secondary:#D6D6D6; --forest-50:#1F1F1F; --forest-500:#D6D6D6; --forest-700:#FFFFFF;
            --good:#D6D6D6; --bad:#9A9A9A;
        }
        [data-palette="inverse"] .btn-primary { background:#FFFFFF; color:#0A0A0A; }
        [data-palette="inverse"] .btn-primary:hover { background:#D6D6D6; }
        [data-palette="inverse"] .btn-secondary { color:#FFFFFF; border-color:#3D3D3D; }
        [data-palette="inverse"] .btn-secondary:hover { background:#1F1F1F; border-color:#9A9A9A; }
        [data-palette="inverse"] .btn-ghost { color:#D6D6D6; }
        [data-palette="inverse"] .btn-ghost:hover { background:#1F1F1F; color:#FFFFFF; }

        /* ----------- BASE RESETS ----------- */
        * { box-sizing: border-box; }
        *, *::before, *::after { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--bg-page);
            color: var(--fg-primary);
            font-family: var(--font-sans);
            font-size: var(--fs-body);
            line-height: var(--lh-body);
            text-rendering: optimizeLegibility;
            font-feature-settings: "ss01" 1;
            overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; display: block; }

        /* ----------- TYPE STYLES ----------- */
        .serif { font-family: var(--font-serif); }
        .mono  { font-family: var(--font-mono); font-feature-settings: 'liga' 0; }
        .num   { font-feature-settings: "tnum" 1, "lnum" 1; }

        .eyebrow {
            display: inline-block;
            font-family: var(--font-sans);
            font-size: var(--fs-micro); font-weight: var(--fw-medium);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); margin: 0;
        }
        .lede {
            font-family: var(--font-serif); font-style: italic;
            font-size: 22px; line-height: 1.5;
            color: var(--fg-tertiary); max-width: 52ch; margin: 0 0 36px;
            text-wrap: pretty;
        }

        /* ----------- LAYOUT ----------- */
        .container { max-width: var(--container-default); margin: 0 auto; padding: 0 32px; }
        .container.wide { max-width: var(--container-wide); }

        /* ----------- HEADER ----------- */
        .site-header {
            position: sticky; top: 0; z-index: 50;
            background: color-mix(in oklab, var(--bg-page) 88%, transparent);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--ink-200);
        }
        .site-header .inner { display: flex; align-items: center; gap: 40px; height: 72px; }
        .site-nav { display: flex; gap: 28px; flex: 1; }
        .site-nav a {
            font-size: var(--fs-body-s); color: var(--fg-secondary); font-weight: var(--fw-medium);
            padding: 6px 0; transition: color var(--dur-quick);
        }
        .site-nav a:hover { color: var(--fg-primary); }
        .header-cta { display: flex; gap: 10px; align-items: center; }

        /* Logo lockup */
        .logo-mono { color: var(--ink-900); display: inline-flex; flex-direction: column; align-items: flex-start; gap: 6px; line-height: 1; }
        .logo-mono.invert { color: var(--parchment-50); }
        .logo-bars { display: block; }
        .logo-words {
            font-family: var(--font-sans); font-weight: var(--fw-medium);
            font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase;
        }
        .logo-sub {
            font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.34em;
            text-transform: uppercase; color: var(--bronze-400); margin-top: 2px;
        }

        /* ----------- BUTTONS ----------- */
        .btn {
            font-family: var(--font-sans);
            font-size: var(--fs-body-s); font-weight: var(--fw-medium);
            padding: 11px 20px; border-radius: var(--radius-md); border: 1px solid transparent;
            cursor: pointer; line-height: 1; letter-spacing: -0.005em;
            transition: all var(--dur-quick) var(--ease-standard);
            display: inline-flex; align-items: center; gap: 8px;
            text-align: center; justify-content: center;
        }
        .btn-primary { background: var(--ink-900); color: var(--parchment-50); }
        .btn-primary:hover { background: var(--ink-700); }
        .btn-accent  { background: var(--bronze-400); color: var(--parchment-50); }
        .btn-accent:hover { background: var(--bronze-500); }
        .btn-secondary { background: transparent; color: var(--ink-900); border-color: var(--ink-300); }
        .btn-secondary:hover { background: var(--parchment-200); border-color: var(--ink-700); }
        .btn-ghost { background: transparent; color: var(--ink-700); padding: 11px 14px; }
        .btn-ghost:hover { background: var(--parchment-200); color: var(--ink-900); }
        .btn-lg { padding: 14px 24px; font-size: 15px; }
        .btn-block { width: 100%; }

        /* ----------- HERO ----------- */
        .hero { padding: 96px 0 120px; position: relative; overflow: hidden; }
        .hero::before {
            content:''; position: absolute; inset: 0; pointer-events: none;
            background: radial-gradient(circle 700px at 100% 0%, var(--bronze-50), transparent 70%);
            opacity: 0.55;
        }
        .hero .container { position: relative; }
        .hero h1 {
            font-family: var(--font-serif); font-weight: var(--fw-regular);
            font-size: var(--fs-display-l);
            line-height: var(--lh-display); letter-spacing: -0.025em;
            margin: 18px 0 24px; max-width: 14ch; text-wrap: balance;
        }
        .hero h1 em { font-style: italic; color: var(--bronze-500); }
        .hero .actions { display: flex; gap: 12px; flex-wrap: wrap; }

        /* ----------- HERO SPLIT (editorial left, login right) ----------- */
        .hero-grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 64px; align-items: center; }
        @media (max-width: 960px) { .hero-grid { grid-template-columns: 1fr; gap: 48px; } }
        .signin-card {
            background: var(--bg-surface); border: 1px solid var(--ink-200);
            border-radius: var(--radius-lg); padding: 32px;
            box-shadow: var(--shadow-lg);
        }
        .signin-card .signin-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 24px; }
        .signin-card h2 {
            font-family: var(--font-serif); font-weight: var(--fw-regular);
            font-size: 32px; line-height: 1.05; letter-spacing: -0.02em;
            margin: 8px 0 0;
        }
        .signin-card h2 em { font-style: italic; color: var(--bronze-500); }
        .signin-card .field-row { margin-bottom: 18px; }
        .signin-card .field-label {
            display: block; font-family: var(--font-sans);
            font-size: var(--fs-micro); font-weight: var(--fw-medium);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); margin-bottom: 6px;
        }
        .signin-card .field {
            width: 100%; background: transparent;
            border: 0; border-bottom: 1px solid var(--ink-300); border-radius: 0;
            padding: 12px 0 10px; font-size: var(--fs-body); color: var(--fg-primary);
            font-family: var(--font-sans); transition: border-color var(--dur-base);
        }
        .signin-card .field:focus { outline: none; border-bottom-color: var(--ink-900); }
        .signin-card .field::placeholder { color: var(--fg-muted); font-weight: var(--fw-light); }
        .signin-card .flash {
            margin-bottom: 18px; padding: 12px 14px;
            font-size: var(--fs-caption); line-height: 1.5;
            border: 1px solid var(--bad); background: var(--accent-soft); color: var(--bronze-700);
            border-radius: var(--radius-md);
        }
        .signin-card .footnote {
            display: flex; gap: 10px; align-items: flex-start;
            margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--ink-200);
            font-size: var(--fs-caption); line-height: 1.5; color: var(--fg-tertiary);
        }
        .signin-card .footnote svg { color: var(--bronze-400); flex-shrink: 0; margin-top: 2px; }

        /* ----------- STAT STRIP ----------- */
        .stat-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 0;
            border: 1px solid var(--ink-200); border-radius: var(--radius-lg);
            overflow: hidden; background: var(--parchment-100); margin-top: 64px;
        }
        @media (max-width: 760px) { .stat-strip { grid-template-columns: repeat(2, 1fr); } }
        .stat { padding: 32px; border-right: 1px solid var(--ink-200); }
        .stat:last-child { border-right: 0; }
        @media (max-width: 760px) {
            .stat:nth-child(2n) { border-right: 0; }
            .stat:nth-child(-n+2) { border-bottom: 1px solid var(--ink-200); }
        }
        .stat .num {
            font-family: var(--font-serif); font-weight: var(--fw-regular);
            font-size: 44px; line-height: 1; letter-spacing: -0.02em;
            color: var(--fg-primary);
        }
        .stat .lbl { font-size: var(--fs-caption); color: var(--fg-tertiary); margin-top: 8px; }

        /* ----------- SECTION ----------- */
        .section { padding: 96px 0; border-top: 1px solid var(--ink-200); }
        .section h2 {
            font-family: var(--font-serif); font-weight: var(--fw-regular);
            font-size: var(--fs-display-m); line-height: 1.1; letter-spacing: -0.02em;
            margin: 12px 0 18px; max-width: 18ch; text-wrap: balance;
        }
        .section h2 em { font-style: italic; color: var(--bronze-500); }
        .section .section-lede {
            font-size: var(--fs-body-l); color: var(--fg-secondary);
            max-width: 56ch; margin: 0;
        }

        /* ----------- FEATURE GRID ----------- */
        .feature-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
            background: var(--ink-200); margin-top: 64px;
            border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            overflow: hidden;
        }
        @media (max-width: 960px) { .feature-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .feature-grid { grid-template-columns: 1fr; } }
        .feature {
            background: var(--bg-page); padding: 36px 32px;
            display: flex; flex-direction: column; gap: 14px; min-height: 240px;
            transition: background var(--dur-quick);
        }
        .feature:hover { background: var(--parchment-100); }
        .feature .feature-eyebrow {
            font-family: var(--font-mono); font-size: var(--fs-micro);
            color: var(--bronze-500); letter-spacing: 0.02em;
        }
        .feature h3 {
            font-family: var(--font-serif); font-size: 26px; font-weight: var(--fw-regular);
            letter-spacing: -0.01em; margin: 0; line-height: 1.2;
        }
        .feature p { font-size: 15px; color: var(--fg-secondary); margin: 0; }

        /* ----------- PROOF / TESTIMONIAL ----------- */
        .proof { background: var(--ink-900); color: var(--parchment-50); padding: 96px 0; }
        .proof blockquote {
            font-family: var(--font-serif); font-weight: var(--fw-regular);
            font-size: 36px; line-height: 1.25; letter-spacing: -0.015em;
            margin: 0; max-width: 22ch; text-wrap: balance;
        }
        .proof blockquote em { font-style: italic; color: var(--bronze-400); }
        .proof cite {
            font-style: normal; font-size: var(--fs-caption);
            color: var(--ink-300); display: block; margin-top: 24px;
            letter-spacing: 0.04em; text-transform: uppercase;
        }
        .proof .eyebrow { color: var(--bronze-400); }

        /* ----------- INTEGRATIONS STRIP ----------- */
        .integrations-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 32px; margin-top: 56px; }
        @media (max-width: 960px) { .integrations-grid { grid-template-columns: 1fr; } }
        .integration-hero {
            background: var(--ink-900); color: var(--parchment-50);
            padding: 32px; border-radius: var(--radius-lg);
        }
        .integration-hero .name { font-family: var(--font-serif); font-size: 36px; font-weight: var(--fw-regular); }
        .integration-hero p { font-size: var(--fs-body-s); color: var(--ink-300); line-height: 1.55; margin-top: 12px; }
        .integration-hero ul { list-style: none; padding: 0; margin: 16px 0 0; font-size: var(--fs-caption); color: var(--ink-300); }
        .integration-hero ul li { padding: 4px 0; }

        .integration-coming {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
            background: var(--ink-200); border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            overflow: hidden;
        }
        @media (max-width: 760px) { .integration-coming { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .integration-coming { grid-template-columns: 1fr; } }
        .integration-tile {
            background: var(--bg-page); padding: 24px;
            display: flex; flex-direction: column; gap: 8px; min-height: 140px;
        }
        .integration-tile .name {
            font-family: var(--font-serif); font-size: 20px; font-weight: var(--fw-regular);
        }
        .integration-tile .detail { font-size: var(--fs-caption); color: var(--fg-tertiary); }
        .integration-tile .eta {
            margin-top: auto; font-family: var(--font-mono);
            font-size: var(--fs-micro); letter-spacing: 0.06em; color: var(--bronze-500);
            text-transform: uppercase;
        }

        /* Status pills */
        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 9px; border: 1px solid; border-radius: var(--radius-pill);
            font-size: 10px; font-weight: var(--fw-medium); letter-spacing: 0.14em; text-transform: uppercase;
        }
        .pill .dot { width: 6px; height: 6px; border-radius: var(--radius-pill); background: currentColor; }
        .pill.live { color: var(--forest-500); border-color: var(--forest-500); background: var(--forest-50); }
        .pill.soon { color: var(--fg-tertiary); border-color: var(--ink-300); background: var(--bg-surface); }
        @keyframes pulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:0.5; transform:scale(0.85); } }
        .pill.live .dot { animation: pulse 2.4s ease-in-out infinite; }

        /* ----------- SECURITY GRID ----------- */
        .security-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
            background: var(--ink-200); margin-top: 56px;
            border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            overflow: hidden;
        }
        @media (max-width: 960px) { .security-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .security-grid { grid-template-columns: 1fr; } }
        .security-cell {
            background: var(--bg-page); padding: 32px;
            display: flex; flex-direction: column; gap: 12px; min-height: 220px;
        }
        .security-cell svg { color: var(--bronze-500); }
        .security-cell h3 {
            font-family: var(--font-serif); font-size: 20px; font-weight: var(--fw-regular);
            margin: 0; line-height: 1.25;
        }
        .security-cell p { font-size: 13px; color: var(--fg-secondary); margin: 0; line-height: 1.55; }

        /* ----------- NEWSROOM ----------- */
        .reg-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
            background: var(--ink-200); border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            overflow: hidden; margin-top: 32px;
        }
        @media (max-width: 960px) { .reg-grid { grid-template-columns: 1fr; } }
        .reg-card {
            background: var(--bg-page); padding: 28px;
            display: flex; flex-direction: column; gap: 12px; min-height: 220px;
        }
        .reg-card .head { display: flex; justify-content: space-between; align-items: baseline; }
        .reg-card .tag { font-family: var(--font-mono); font-size: var(--fs-micro); color: var(--bronze-500); letter-spacing: 0.06em; text-transform: uppercase; }
        .reg-card .date { font-family: var(--font-mono); font-size: var(--fs-micro); color: var(--fg-tertiary); }
        .reg-card h4 { font-family: var(--font-serif); font-size: 19px; font-weight: var(--fw-regular); margin: 0; line-height: 1.3; }
        .reg-card p { font-size: var(--fs-caption); color: var(--fg-secondary); margin: 0; line-height: 1.55; }
        .reg-card a { margin-top: auto; font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow); text-transform: uppercase; font-weight: var(--fw-medium); color: var(--fg-primary); }

        .events { margin-top: 32px; border-top: 1px solid var(--ink-200); }
        .event-row {
            display: grid; grid-template-columns: 160px 1fr 200px 110px;
            gap: 24px; padding: 24px 0; border-bottom: 1px solid var(--ink-200);
            transition: background var(--dur-quick);
        }
        .event-row:hover { background: var(--parchment-100); }
        @media (max-width: 760px) { .event-row { grid-template-columns: 1fr; gap: 8px; } }
        .event-row .date { font-family: var(--font-mono); font-size: var(--fs-micro); color: var(--bronze-500); letter-spacing: 0.06em; text-transform: uppercase; }
        .event-row h4 { font-family: var(--font-serif); font-size: 19px; font-weight: var(--fw-regular); margin: 0; line-height: 1.3; }
        .event-row p { font-size: var(--fs-caption); color: var(--fg-secondary); margin: 4px 0 0; }
        .event-row .loc { font-size: var(--fs-caption); color: var(--fg-tertiary); align-self: center; }
        .event-row .cta { font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow); text-transform: uppercase; font-weight: var(--fw-medium); color: var(--fg-primary); align-self: center; text-align: right; }

        .recognition-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
            background: var(--ink-200); border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            overflow: hidden; margin-top: 32px;
        }
        @media (max-width: 960px) { .recognition-grid { grid-template-columns: 1fr; } }
        .recognition-cell {
            background: var(--bg-page); padding: 24px;
            display: flex; gap: 18px; align-items: flex-start; min-height: 110px;
        }
        .recognition-cell .badge {
            font-family: var(--font-serif); font-size: 22px; font-weight: var(--fw-regular);
            color: var(--bronze-500); flex-shrink: 0;
        }
        .recognition-cell p { font-size: var(--fs-caption); color: var(--fg-secondary); margin: 0; line-height: 1.5; }

        /* ----------- FOOTER ----------- */
        .site-footer {
            background: var(--ink-900); color: var(--parchment-50);
            padding: 80px 0 40px;
        }
        .footer-grid {
            display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr;
            gap: 48px; padding-bottom: 56px;
        }
        @media (max-width: 960px) { .footer-grid { grid-template-columns: 1fr 1fr; gap: 32px; } }
        @media (max-width: 600px) { .footer-grid { grid-template-columns: 1fr; } }
        .footer-col h4 {
            font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--bronze-400); margin: 0 0 16px; font-weight: var(--fw-medium);
        }
        .footer-col a {
            display: block; font-size: var(--fs-body-s);
            color: rgba(251,248,241,0.75); padding: 4px 0;
            transition: color var(--dur-quick);
        }
        .footer-col a:hover { color: var(--parchment-50); }
        .footer-bottom {
            display: flex; justify-content: space-between; align-items: center;
            padding-top: 28px; border-top: 1px solid var(--ink-700);
            font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: rgba(251,248,241,0.45);
        }
        @media (max-width: 600px) { .footer-bottom { flex-direction: column; gap: 12px; align-items: flex-start; } }
        .footer-brand p { color: rgba(251,248,241,0.65); font-size: var(--fs-body-s); margin: 18px 0 0; max-width: 36ch; line-height: 1.55; }
        .footer-brand .domain { display: inline-flex; align-items: center; gap: 6px; margin-top: 18px; font-size: var(--fs-body-s); color: var(--parchment-50); }
        .footer-brand .domain:hover { color: var(--bronze-400); }

        /* ----------- REVEAL ANIMATIONS ----------- */
        @keyframes rise { 0% { opacity:0; transform: translateY(18px); } 100% { opacity:1; transform: translateY(0); } }
        .reveal { animation: rise 1s var(--ease-emphasis) both; }
        .scroll-reveal {
            opacity: 0; transform: translateY(28px);
            transition: opacity 0.9s var(--ease-emphasis), transform 0.9s var(--ease-emphasis);
        }
        .scroll-reveal.is-visible { opacity: 1; transform: translateY(0); }

        /* ----------- TWEAKS PANEL (palette switcher) ----------- */
        .tweaks-toggle {
            position: fixed; bottom: 24px; right: 24px; z-index: 100;
            width: 44px; height: 44px; border-radius: var(--radius-pill);
            background: var(--ink-900); color: var(--parchment-50);
            border: 0; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--shadow-lg);
            transition: transform var(--dur-quick);
        }
        .tweaks-toggle:hover { transform: scale(1.05); }
        .tweaks-panel {
            position: fixed; bottom: 80px; right: 24px; z-index: 100;
            width: 320px; max-height: calc(100vh - 120px); overflow-y: auto;
            background: var(--bg-surface); border: 1px solid var(--ink-200);
            border-radius: var(--radius-lg); padding: 20px;
            box-shadow: var(--shadow-xl);
            transform: translateY(8px) scale(0.96); opacity: 0; pointer-events: none;
            transition: transform var(--dur-base), opacity var(--dur-base);
        }
        .tweaks-panel.open { transform: translateY(0) scale(1); opacity: 1; pointer-events: auto; }
        .tweaks-panel h5 {
            font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); margin: 0 0 12px; font-weight: var(--fw-medium);
        }
        .tweaks-panel .palette-list { display: flex; flex-direction: column; gap: 6px; }
        .tweaks-panel .palette-card {
            display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            background: var(--parchment-100); border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            cursor: pointer; text-align: left; transition: all var(--dur-quick);
            font-family: var(--font-sans); width: 100%;
        }
        .tweaks-panel .palette-card.active { background: var(--accent-soft); border-color: var(--accent); }
        .tweaks-panel .palette-card .swatches { display: flex; border: 1px solid var(--ink-200); border-radius: 3px; overflow: hidden; flex-shrink: 0; }
        .tweaks-panel .palette-card .swatches span { display: block; width: 14px; height: 28px; }
        .tweaks-panel .palette-card .meta { flex: 1; min-width: 0; }
        .tweaks-panel .palette-card .meta .label { font-size: 13px; font-weight: var(--fw-medium); color: var(--fg-primary); line-height: 1.2; }
        .tweaks-panel .palette-card .meta .sub { font-size: 11px; color: var(--fg-tertiary); margin-top: 2px; line-height: 1.3; }
    </style>
</head>
<body data-palette="warm">

{{-- ═══════════════════════════════════════════════════════════════════════════
   HEADER — kit's site-header pattern
═══════════════════════════════════════════════════════════════════════════ --}}
<header class="site-header">
    <div class="container wide inner">
        <a href="#top" class="logo-mono">
            <svg class="logo-bars" width="56" height="26" viewBox="0 0 56 26" fill="currentColor" aria-hidden="true">
                <rect x="0" y="2"  width="56" height="6"/>
                <rect x="0" y="11" width="36" height="6"/>
                <rect x="0" y="20" width="56" height="6"/>
            </svg>
            <div>
                <div class="logo-words">Elite Advisors</div>
                <div class="logo-sub">Business Management Suite · BMS</div>
            </div>
        </a>
        <nav class="site-nav">
            <a href="#capabilities">Capabilities</a>
            <a href="#integrations">Integrations</a>
            <a href="#security">Security</a>
            <a href="#newsroom">Newsroom</a>
        </nav>
        <div class="header-cta">
            <a href="https://www.eliteadvisors.cm-ea.com" target="_blank" rel="noopener" class="btn btn-ghost">eliteadvisors.cm-ea.com ↗</a>
            <a href="#signin" class="btn btn-primary">Sign in</a>
        </div>
    </div>
</header>

{{-- ═══════════════════════════════════════════════════════════════════════════
   HERO — eyebrow · serif headline · italic lede · login form (right side)
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="top" class="hero">
    <div class="container wide">
        <div class="hero-grid">

            {{-- ----------- Editorial left ----------- --}}
            <div>
                <p class="eyebrow reveal" style="animation-delay: 0.05s;">Business Management Suite · BMS</p>
                <h1 class="reveal" style="animation-delay: 0.18s;">
                    Operating intelligence,<br>
                    audit-grade reporting,<br>
                    <em>one console.</em>
                </h1>
                <p class="lede reveal" style="animation-delay: 0.32s;">
                    Elite Advisors BMS unifies real-time financial intelligence, statutory reporting, and tax compliance into a single principal-grade workspace — engineered on top of Odoo, designed for the way you actually run the firm.
                </p>
                <div class="actions reveal" style="animation-delay: 0.46s;">
                    <a href="#signin" class="btn btn-primary btn-lg">Sign in to BMS →</a>
                    <a href="#capabilities" class="btn btn-secondary btn-lg">Explore the suite</a>
                </div>
            </div>

            {{-- ----------- Sign-in right ----------- --}}
            <div id="signin" class="signin-card reveal" style="animation-delay: 0.4s;">
                <div class="signin-head">
                    <div>
                        <p class="eyebrow">Sign in</p>
                        <h2>Welcome <em>back.</em></h2>
                    </div>
                </div>

                @if (session('flash'))
                    <div class="flash">{{ session('flash.msg') }}</div>
                @endif

                <form method="POST" action="{{ $tenantSlug ? '/t/'.$tenantSlug.'/login' : '/login' }}">
                    @csrf
                    <div class="field-row">
                        <label class="field-label" for="login-username">Username</label>
                        <input id="login-username" name="username" autofocus required autocomplete="username" class="field" placeholder="principal@firm">
                    </div>
                    <div class="field-row">
                        <label class="field-label" for="login-password">Password</label>
                        <input id="login-password" name="password" type="password" required autocomplete="current-password" class="field" placeholder="••••••••••">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top: 8px;">Sign in to BMS →</button>
                </form>

                <div class="footnote">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                    <span>Sessions are encrypted, scoped to your engagement, and audit-logged in line with internal controls.</span>
                </div>
            </div>
        </div>

        {{-- ----------- Stat strip ----------- --}}
        @php
            $svcCount = count(config('modules.services', []));
            $modCount = count(config('modules.modules', []));
        @endphp
        <div class="stat-strip scroll-reveal">
            <div class="stat">
                <div class="num">{{ $svcCount }}</div>
                <div class="lbl">Services in the suite</div>
            </div>
            <div class="stat">
                <div class="num">{{ $modCount }}</div>
                <div class="lbl">Modules across services</div>
            </div>
            <div class="stat">
                <div class="num">8</div>
                <div class="lbl">Industry templates</div>
            </div>
            <div class="stat">
                <div class="num">v17</div>
                <div class="lbl">Odoo versions supported</div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   FEATURES — kit 3×2 hairline-divided grid · capabilities of the suite
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="capabilities" class="section">
    <div class="container wide">
        <p class="eyebrow scroll-reveal">Capabilities</p>
        <h2 class="scroll-reveal">A console that answers <em>one question per pane.</em></h2>
        <p class="section-lede scroll-reveal">
            Each service in BMS is built to the standard of a Big-Four engagement —
            but folded into the operating cadence of the principal who actually runs the day.
        </p>

        @php
            $caps = [
                ['01 · BUSINESS INTELLIGENCE', 'KPIs grounded in your ledger.',
                 'Live revenue, margin, AR days, and treasury position — refreshed from Odoo, drillable to the invoice. Variance bands compare actual against plan and prior year so the narrative writes itself.'],
                ['02 · FINANCIAL REPORTING',  'Statutory rigour, operating speed.',
                 'Trial balance, balance sheet, P&L, and cash flow — backed by GL-quality reconciliation and OHADA AUDCIF presentation, with management-grade commentary alongside.'],
                ['03 · TAX COMPLIANCE',       'Filings before they age into exposure.',
                 'CEMAC VAT calendar, withholding, and CIT advance schedules surfaced before deadline. Invoice-level CGI checks flag exposure on contact, not on inspection.'],
                ['04 · WORKING CAPITAL',      'AR · AP · inventory in one view.',
                 'Receivables, payables, and inventory cycles surfaced together. See whose cash is sitting where — and what it is costing this quarter.'],
                ['05 · FINANCIAL PLANNING',   'Budget on top of your ledger.',
                 'Upload budgets line-by-line, compare against live actuals, and write commentary inline. Forecast and scenario modelling on the same axis. Coming Q2 2026.'],
                ['06 · MACRO & TREASURY',     'External signal, internal cadence.',
                 'BEAC rates, World Bank macro indicators, and live FX feeds folded into your treasury view — so the principal sees what the ledger says alongside what the market says.'],
            ];
        @endphp

        <div class="feature-grid scroll-reveal">
            @foreach ($caps as [$eb, $title, $body])
                <div class="feature">
                    <div class="feature-eyebrow">{{ $eb }}</div>
                    <h3>{{ $title }}</h3>
                    <p>{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   PROOF — full-width ink section · serif blockquote
═══════════════════════════════════════════════════════════════════════════ --}}
<section class="proof scroll-reveal">
    <div class="container wide">
        <p class="eyebrow">From the principals</p>
        <blockquote>
            We replaced four spreadsheets and an analyst's weekend.
            The quarter <em>closes itself</em>, and the commentary reads like ours.
        </blockquote>
        <cite>Managing Partner · Yaoundé</cite>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   INTEGRATIONS — Odoo featured · roadmap for SAP/Dynamics/Sage etc.
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="integrations" class="section">
    <div class="container wide">
        <p class="eyebrow scroll-reveal">Integrations</p>
        <h2 class="scroll-reveal">Built on top of <em>your stack.</em></h2>
        <p class="section-lede scroll-reveal">
            BMS sits beside your operating system, not on top of it.
            We start where you start — today, with Odoo. The roadmap below is sequenced by client demand.
        </p>

        <div class="integrations-grid scroll-reveal">
            <div class="integration-hero">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div class="name">Odoo</div>
                    <span class="pill live"><span class="dot"></span>Live</span>
                </div>
                <p>Native bi-directional sync over JSON-RPC. Modules supported: Accounting, Sales, Purchase, Inventory, CRM, HR. Every BMS reading writes back a reconciliation marker.</p>
                <ul>
                    <li>· 5-minute refresh cadence</li>
                    <li>· Per-tenant credentials, encrypted at rest</li>
                    <li>· v15, v16, v17 supported</li>
                </ul>
            </div>

            @php
                $coming = [
                    ['SAP',                'S/4HANA',                'Q3 2026'],
                    ['Microsoft Dynamics', '365 Business Central',   'Q3 2026'],
                    ['NetSuite',           'OneWorld',               'Q4 2026'],
                    ['Sage',               'X3 / Intacct',           'Q4 2026'],
                    ['QuickBooks',         'Online',                 'Q1 2027'],
                    ['Xero',               'Practice Manager',       'Q1 2027'],
                ];
            @endphp
            <div class="integration-coming">
                @foreach ($coming as [$name, $detail, $eta])
                    <div class="integration-tile">
                        <div style="display:flex; justify-content:space-between; align-items:baseline;">
                            <div class="name">{{ $name }}</div>
                            <span class="pill soon">Soon</span>
                        </div>
                        <div class="detail">{{ $detail }}</div>
                        <div class="eta">ETA {{ $eta }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   SECURITY — six pillars
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="security" class="section">
    <div class="container wide">
        <p class="eyebrow scroll-reveal">Security & Trust</p>
        <h2 class="scroll-reveal">Engineered for the <em>audit you'd run.</em></h2>
        <p class="section-lede scroll-reveal">
            BMS holds principal-grade financial data. The platform is built to the controls
            a Big-Four engagement would expect — and tested against the way your own audit
            team would actually open the hood.
        </p>

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

        <div class="security-grid scroll-reveal">
            @foreach ($pillars as [$title, $body, $icon])
                <div class="security-cell">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
                    </svg>
                    <h3>{{ $title }}</h3>
                    <p>{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   NEWSROOM — regulatory pulse · events · recognition
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="newsroom" class="section">
    <div class="container wide">
        <p class="eyebrow scroll-reveal">Newsroom</p>
        <h2 class="scroll-reveal">What's moving <em>around our clients.</em></h2>
        <p class="section-lede scroll-reveal">
            Regulatory changes, events worth attending, and the recognitions
            we've earned alongside the firms we serve.
        </p>

        {{-- Regulatory pulse --}}
        @php
            $regs = [
                ['CEMAC · VAT',    'Quarterly VAT calendar — Q2 2026 deadlines',
                 'Updated CEMAC VAT calendar with corrected filing windows for monthly and quarterly regimes. Action required: confirm regime classification by 30 April.',
                 '04 Apr 2026'],
                ['OHADA · AUDCIF', 'AUDCIF disclosure update for management reporting',
                 'New presentation requirements for management commentary alongside statutory accounts. Affects entities subject to AUDCIF Articles 8–11.',
                 '28 Mar 2026'],
                ['IFRS · IFRS 18', 'IFRS 18 effective date approaches — Jan 2027',
                 'IFRS 18 reshapes the income statement into three categories. Early-adopter firms should begin restating comparative periods this quarter.',
                 '21 Mar 2026'],
            ];
        @endphp
        <div class="reg-grid scroll-reveal">
            @foreach ($regs as [$tag, $title, $body, $date])
                <article class="reg-card">
                    <div class="head"><span class="tag">{{ $tag }}</span><span class="date">{{ $date }}</span></div>
                    <h4>{{ $title }}</h4>
                    <p>{{ $body }}</p>
                    <a href="#">Read briefing →</a>
                </article>
            @endforeach
        </div>

        {{-- Events --}}
        <h3 style="font-family:var(--font-serif); font-size:24px; font-weight:var(--fw-regular); margin: 56px 0 0;">Events & roundtables</h3>
        @php
            $events = [
                ['30 Apr 2026', 'CFO Breakfast · Douala',          'Closed-door roundtable on operating discipline in volatile cycles. By invitation.', 'Douala · Cameroon',  'Open'],
                ['15 May 2026', 'Webinar — Industry KPIs by sector', 'Live walk-through of BMS industry templates. 60 minutes incl. Q&A.',               'Online · 14:00 GMT', 'Register'],
                ['12 Jun 2026', 'Africa Finance & Strategy Summit', 'Elite Advisors hosts a panel on operating intelligence for mid-market firms.',     'Kigali · Rwanda',    'Limited'],
                ['18 Sep 2026', 'BMS User Conference 2026',        'Annual conference for principals operating on BMS. Workshops, roadmap, networking.', 'Yaoundé · Cameroon', 'Save the date'],
            ];
        @endphp
        <div class="events scroll-reveal">
            @foreach ($events as [$date, $title, $body, $loc, $cta])
                <a href="#" class="event-row">
                    <div class="date">{{ $date }}</div>
                    <div>
                        <h4>{{ $title }}</h4>
                        <p>{{ $body }}</p>
                    </div>
                    <div class="loc">{{ $loc }}</div>
                    <div class="cta">{{ $cta }} →</div>
                </a>
            @endforeach
        </div>

        {{-- Recognition --}}
        <h3 style="font-family:var(--font-serif); font-size:24px; font-weight:var(--fw-regular); margin: 56px 0 0;">Recognition & memberships</h3>
        @php
            $recognition = [
                ['ONECCA',    'Member firm — Ordre National des Experts Comptables du Cameroun'],
                ['OHADA',     'Compliant with OHADA AUDCIF reporting framework'],
                ['IFAC',      'Aligned to IFAC International Standards on Auditing'],
                ['CEMAC',     'Filing-ready for CEMAC VAT, withholding & CIT regimes'],
                ['ISO 27001', 'Controls aligned to ISO 27001 information security'],
                ['SOC 2',     'Trust Services Criteria mapped to AICPA SOC 2 framework'],
            ];
        @endphp
        <div class="recognition-grid scroll-reveal">
            @foreach ($recognition as [$badge, $body])
                <div class="recognition-cell">
                    <div class="badge">{{ $badge }}</div>
                    <p>{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   FOOTER — kit's site-footer pattern
═══════════════════════════════════════════════════════════════════════════ --}}
<footer class="site-footer">
    <div class="container wide">
        <div class="footer-grid">
            <div class="footer-col footer-brand">
                <a href="#top" class="logo-mono invert">
                    <svg class="logo-bars" width="56" height="26" viewBox="0 0 56 26" fill="currentColor">
                        <rect x="0" y="2"  width="56" height="6"/>
                        <rect x="0" y="11" width="36" height="6"/>
                        <rect x="0" y="20" width="56" height="6"/>
                    </svg>
                    <div>
                        <div class="logo-words">Elite Advisors</div>
                        <div class="logo-sub">Business Management Suite · BMS</div>
                    </div>
                </a>
                <p>A principal-grade business management suite — engineered for the rigour your firm deserves and the cadence at which you actually run.</p>
                <a href="https://www.eliteadvisors.cm-ea.com" target="_blank" rel="noopener" class="domain">
                    www.eliteadvisors.cm-ea.com
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3h7v7M10 14L21 3M21 14v7H3V3h7"/></svg>
                </a>
            </div>
            <div class="footer-col">
                <h4>Suite</h4>
                <a href="#capabilities">Business Intelligence</a>
                <a href="#capabilities">Financial Reporting</a>
                <a href="#capabilities">Tax Compliance</a>
                <a href="#capabilities">Financial Planning</a>
            </div>
            <div class="footer-col">
                <h4>Platform</h4>
                <a href="#integrations">Integrations</a>
                <a href="#security">Security</a>
                <a href="#newsroom">Newsroom</a>
                <a href="#signin">Sign in</a>
            </div>
            <div class="footer-col">
                <h4>Engage</h4>
                <a href="https://www.eliteadvisors.cm-ea.com">Contact</a>
                <a href="#newsroom">Events</a>
                <a href="#newsroom">Briefings</a>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© 2026 Elite Advisors · Business Management Suite</span>
            <span>Confidential — authorized use only · v1.0</span>
        </div>
    </div>
</footer>

{{-- ═══════════════════════════════════════════════════════════════════════════
   TWEAKS PANEL — palette switcher (kit signature)
═══════════════════════════════════════════════════════════════════════════ --}}
<button class="tweaks-toggle" aria-label="Open palette switcher" id="tweaks-toggle">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="12" cy="12" r="9"/>
        <path d="M12 3a9 9 0 0 0 0 18M3 12h18"/>
    </svg>
</button>
<div class="tweaks-panel" id="tweaks-panel" role="dialog" aria-label="Palette switcher">
    <h5>Palette</h5>
    <div class="palette-list">
        @php
            $palettes = [
                ['warm',      'Warm',      'Original parchment + bronze',         ['#FBF8F1','#F5F0E4','#B07F3B','#14110F']],
                ['mono',      'Mono',      'Pure black on pure white',            ['#FFFFFF','#F2F2F2','#5C5C5C','#000000']],
                ['newsprint', 'Newsprint', 'Soft warm white, near-black ink',     ['#FBFAF7','#F2F1ED','#5F5F5A','#0E0E0C']],
                ['graphite',  'Graphite',  'Light grey page, charcoal ink',       ['#F4F4F4','#EBEBEB','#6B6B6F','#16161A']],
                ['inverse',   'Inverse',   'Black page, white ink',               ['#0A0A0A','#1F1F1F','#9A9A9A','#FFFFFF']],
            ];
        @endphp
        @foreach ($palettes as [$key, $label, $sub, $sw])
            <button type="button" class="palette-card{{ $key === 'warm' ? ' active' : '' }}" data-palette-key="{{ $key }}">
                <div class="swatches">
                    @foreach ($sw as $color)
                        <span style="background:{{ $color }};"></span>
                    @endforeach
                </div>
                <div class="meta">
                    <div class="label">{{ $label }}</div>
                    <div class="sub">{{ $sub }}</div>
                </div>
            </button>
        @endforeach
    </div>
</div>

<script>
    // Scroll-reveal on intersection
    (function () {
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

    // TweaksPanel — palette switcher
    (function () {
        const toggle = document.getElementById('tweaks-toggle');
        const panel  = document.getElementById('tweaks-panel');
        const cards  = panel.querySelectorAll('.palette-card');
        const STORAGE_KEY = 'bms.palette';

        // Restore preference
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved && ['warm','mono','newsprint','graphite','inverse'].includes(saved)) {
                document.body.setAttribute('data-palette', saved);
                cards.forEach(c => c.classList.toggle('active', c.dataset.paletteKey === saved));
            }
        } catch (_) {}

        toggle.addEventListener('click', () => panel.classList.toggle('open'));
        document.addEventListener('click', (e) => {
            if (!panel.contains(e.target) && !toggle.contains(e.target)) panel.classList.remove('open');
        });

        cards.forEach(card => {
            card.addEventListener('click', () => {
                const key = card.dataset.paletteKey;
                document.body.setAttribute('data-palette', key);
                cards.forEach(c => c.classList.toggle('active', c === card));
                try { localStorage.setItem(STORAGE_KEY, key); } catch (_) {}
            });
        });
    })();
</script>

</body>
</html>
