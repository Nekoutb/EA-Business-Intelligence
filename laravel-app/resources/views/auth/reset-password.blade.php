<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Choose a new password · Business Management Suite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    @include('auth._password-styles')
</head>
<body>
<div class="card">
    <div class="eyebrow">Business Management Suite</div>
    @if ($valid)
        <h1>Choose a new password</h1>
        <p class="sub">Once saved, every device signed in to this account is signed out.</p>

        <form method="POST" action="{{ $base }}/reset-password/{{ $token }}">
            @csrf
            <label for="password">New password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required minlength="10" autofocus>
            <div class="hint">At least 10 characters.</div>
            @error('password')<div class="err">{{ $message }}</div>@enderror

            <label for="password_confirmation">New password, again</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required minlength="10">

            <button type="submit">Save new password</button>
        </form>
    @else
        <h1>This link has expired</h1>
        <p class="sub">Reset links work once and only for {{ \App\Services\PasswordResetService::TTL_MINUTES }} minutes. Request a fresh one — it takes a few seconds.</p>
        <a class="btn" href="{{ $base }}/forgot-password">Request a new link</a>
    @endif

    <div class="foot"><a href="{{ $base }}/login">← Back to sign in</a></div>
</div>
</body>
</html>
