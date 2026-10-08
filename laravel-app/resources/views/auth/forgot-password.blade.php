<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forgot password · Business Management Suite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    @include('auth._password-styles')
</head>
<body>
<div class="card">
    <div class="eyebrow">Business Management Suite</div>
    <h1>Forgot your password?</h1>
    <p class="sub">Enter the email you sign in with. We'll send you a link to choose a new password.</p>

    @if (session('flash'))
        <div class="note {{ session('flash.type') === 'error' ? 'note--err' : 'note--ok' }}" role="status">{{ session('flash.msg') }}</div>
    @endif

    <form method="POST" action="{{ $base }}/forgot-password">
        @csrf
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        @error('email')<div class="err">{{ $message }}</div>@enderror
        <button type="submit">Send reset link</button>
    </form>

    <div class="foot"><a href="{{ $base }}/login">← Back to sign in</a></div>
</div>
</body>
</html>
