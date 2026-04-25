<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · {{ config('bi.brand') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://rsms.me/"><link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>html{font-family:'Inter',ui-sans-serif,system-ui}</style>
</head>
<body class="h-full bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 flex items-center justify-center p-4">

    <div class="relative w-full max-w-md">
        {{-- Decorative glow --}}
        <div class="absolute -inset-0.5 rounded-2xl bg-gradient-to-r from-violet-600 via-fuchsia-500 to-amber-500 opacity-40 blur-xl"></div>

        <div class="relative bg-white rounded-2xl shadow-2xl p-8 space-y-6">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold shadow-lg">
                    {{ strtoupper(substr(config('bi.brand'), 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-lg font-bold text-slate-900 leading-tight">{{ config('bi.brand') }}</h1>
                    <p class="text-xs text-slate-500">Business Intelligence Console</p>
                </div>
            </div>

            @if (session('flash'))
              <div class="text-xs px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700">
                  {{ session('flash.msg') }}
              </div>
            @endif

            <form method="POST" action="/login" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Username</label>
                    <input name="username" autofocus required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Password</label>
                    <input name="password" type="password" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition">
                </div>
                <button class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium py-2.5 rounded-lg text-sm shadow-lg shadow-indigo-900/30 transition">
                    Sign in
                </button>
            </form>

            <p class="text-[11px] text-slate-400 text-center">
                Credentials defined in <code class="bg-slate-100 px-1 rounded">.env</code>
            </p>
        </div>
    </div>
</body>
</html>
