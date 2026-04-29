<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Session expired — {{ config('app.name', 'EA Business Intelligence') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: #faf8f5; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4">
    <div class="bg-white border border-[#e8e4de] rounded-xl p-8 max-w-md w-full text-center shadow-sm">
        <div class="w-12 h-12 mx-auto mb-4 rounded-full bg-warm-100 border border-warm-200 flex items-center justify-center">
            <svg class="w-6 h-6 text-warm-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z" />
            </svg>
        </div>
        <h1 class="text-lg font-semibold text-[#1e3d44] mb-2">Your session has expired</h1>
        <p class="text-sm text-[#5a6a70] leading-relaxed mb-5">
            For your security, the page was open too long and the form token timed out.
            Just refresh and try again — your account is fine.
        </p>
        <div class="flex flex-col sm:flex-row gap-2 justify-center">
            <button onclick="history.back()"
                    class="text-sm bg-[#1e3d44] hover:bg-[#16313a] text-white rounded-lg px-4 py-2 transition">
                Go back &amp; retry
            </button>
            <a href="/" class="text-sm bg-white hover:bg-[#faf8f5] border border-[#e8e4de] text-[#1e3d44] rounded-lg px-4 py-2 transition">
                Home
            </a>
        </div>
        <p class="text-[11px] text-[#a09d95] mt-5">419 · CSRF token mismatch</p>
    </div>
</body>
</html>
