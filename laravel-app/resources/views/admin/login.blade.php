<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · Platform Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://rsms.me/"><link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>html{font-family:'Inter',ui-sans-serif,system-ui}</style>
</head>
<body class="h-full bg-gradient-to-br from-slate-950 via-rose-950 to-slate-950 flex items-center justify-center p-4">
    <div class="relative w-full max-w-md">
        <div class="absolute -inset-0.5 rounded-2xl bg-gradient-to-r from-rose-500 to-orange-500 opacity-30 blur-xl"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl p-8 space-y-6">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500 to-orange-500 flex items-center justify-center text-white font-bold shadow-lg">A</div>
                <div>
                    <h1 class="text-lg font-bold text-slate-900 leading-tight">Platform Admin</h1>
                    <p class="text-xs text-slate-500">Super-admin backend</p>
                </div>
            </div>

            @if (session('flash'))
              <div class="text-xs px-3 py-2 rounded-lg {{ session('flash.type') === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-rose-50 border border-rose-200 text-rose-700' }}">
                  {{ session('flash.msg') }}
              </div>
            @endif

            <form method="POST" action="/admin/login" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                    <input name="email" type="email" autofocus required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-transparent transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Password</label>
                    <input name="password" type="password" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-transparent transition">
                </div>
                <button class="w-full bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-medium py-2.5 rounded-lg text-sm shadow-lg transition">
                    Sign in
                </button>
                <p class="text-center"><a href="/admin/forgot-password" class="text-xs text-slate-500 hover:text-slate-800 underline">Forgot password?</a></p>
            </form>
        </div>
    </div>
</body>
</html>
