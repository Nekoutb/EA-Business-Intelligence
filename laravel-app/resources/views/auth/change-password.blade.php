<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Set your password · Business Management Suite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page:#FBF8F1; --bg-surface:#F5F0E4; --ink-200:#D8D0C4; --ink-300:#B8AFA3;
            --fg-primary:#14110F; --fg-secondary:#4A4239; --fg-muted:#948A7E;
            --accent:#B07F3B; --accent-hover:#8E6228; --bad:#B26A53; --good:#3E5A44;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Hanken Grotesk', -apple-system, 'Segoe UI', sans-serif;
            background: var(--bg-page); color: var(--fg-primary);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .card {
            width: 100%; max-width: 420px; background: var(--bg-surface);
            border: 1px solid var(--ink-200); border-radius: 8px; padding: 32px;
            box-shadow: 0 12px 32px rgba(31,27,23,.08), 0 4px 8px rgba(31,27,23,.04);
        }
        .eyebrow { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; color: var(--accent); font-weight: 600; margin-bottom: 8px; }
        h1 { font-size: 22px; font-weight: 700; letter-spacing: -.01em; margin-bottom: 8px; }
        .sub { font-size: 14px; color: var(--fg-secondary); line-height: 1.55; margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 600; color: var(--fg-secondary); margin: 16px 0 6px; }
        input[type=password] {
            width: 100%; padding: 10px 12px; font: inherit; font-size: 15px;
            border: 1px solid var(--ink-300); border-radius: 4px; background: #fff; color: var(--fg-primary);
        }
        input[type=password]:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
        .hint { font-size: 12px; color: var(--fg-muted); margin-top: 6px; }
        .err { font-size: 13px; color: var(--bad); margin-top: 6px; font-weight: 500; }
        button {
            width: 100%; margin-top: 24px; padding: 12px; font: inherit; font-size: 15px; font-weight: 600;
            background: var(--accent); color: #fff; border: 0; border-radius: 4px; cursor: pointer;
        }
        button:hover { background: var(--accent-hover); }
        .foot { margin-top: 16px; text-align: center; font-size: 13px; }
        .foot a { color: var(--fg-muted); }
    </style>
</head>
<body>
<div class="card">
    <div class="eyebrow">Business Management Suite</div>
    @if ($forced)
        <h1>Set your own password</h1>
        <p class="sub">You signed in with a temporary password that was emailed to you.
           Choose a password of your own to continue — the temporary one stops working the moment you save.</p>
    @else
        <h1>Change your password</h1>
        <p class="sub">Enter your current password, then choose a new one.</p>
    @endif

    <form method="POST" action="{{ $tenantSlug ? '/t/' . $tenantSlug . '/account/password' : '/account/password' }}">
        @csrf
        <label for="current_password">{{ $forced ? 'Temporary password (from the email)' : 'Current password' }}</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required autofocus>
        @error('current_password')<div class="err">{{ $message }}</div>@enderror

        <label for="password">New password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="10">
        <div class="hint">At least 10 characters, and different from the temporary one.</div>
        @error('password')<div class="err">{{ $message }}</div>@enderror

        <label for="password_confirmation">New password, again</label>
        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required minlength="10">

        <button type="submit">Save password &amp; continue</button>
    </form>

    <div class="foot"><a href="{{ $tenantSlug ? '/t/' . $tenantSlug . '/logout' : '/logout' }}">Sign out</a></div>
</div>
</body>
</html>
