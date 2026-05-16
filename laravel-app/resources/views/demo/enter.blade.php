<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enter the demo · BMS — Elite Advisors</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        html { font-family: 'Hanken Grotesk', system-ui, sans-serif; }
        body { background: #FBF8F1; color: #14110F; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-2xl">
        <div class="flex items-center gap-3 mb-8">
            <a href="/demo" class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                Back to demo overview
            </a>
        </div>

        <div class="bg-white border border-stone-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="bg-gradient-to-r from-amber-50 to-stone-50 border-b border-stone-200 p-6">
                <div class="text-[11px] uppercase tracking-widest text-amber-700 font-semibold mb-2">Sandbox · Fictional data only</div>
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Open a private demo sandbox</h1>
                <p class="text-[13px] text-slate-600 leading-relaxed">
                    Each visitor gets their own isolated copy of the BMS, pre-seeded with a realistic Cameroon-SME dataset
                    (10 customers, 30 invoices, 90 days of activity, OHADA chart of accounts). Edit invoices, book journal
                    entries, use the natural-language console — and watch every KPI re-compute live. Sessions auto-expire
                    after 2&nbsp;hours.
                </p>
            </div>

            @if (session('flash.msg'))
                <div class="mx-6 mt-4 px-4 py-2.5 rounded-lg text-sm border
                    {{ session('flash.type') === 'error'
                        ? 'bg-rose-50 border-rose-200 text-rose-800'
                        : 'bg-emerald-50 border-emerald-200 text-emerald-800' }}">
                    {{ session('flash.msg') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mx-6 mt-4 px-4 py-2.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-sm">
                    @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <form method="POST" action="/demo/start" class="p-6 space-y-4">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5 uppercase tracking-wider">Your name *</label>
                        <input name="name" type="text" required value="{{ $oldName ?? '' }}"
                               class="w-full border border-stone-300 rounded-lg px-3.5 py-2.5 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100"
                               placeholder="Jane Doe">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5 uppercase tracking-wider">Work email *</label>
                        <input name="email" type="email" required value="{{ $oldEmail ?? '' }}"
                               class="w-full border border-stone-300 rounded-lg px-3.5 py-2.5 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100"
                               placeholder="jane@yourcompany.com">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5 uppercase tracking-wider">Company</label>
                        <input name="company" type="text" value="{{ $oldCompany ?? '' }}"
                               class="w-full border border-stone-300 rounded-lg px-3.5 py-2.5 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100"
                               placeholder="Acme Ltd · optional but appreciated">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5 uppercase tracking-wider">Your role</label>
                        <input name="role" type="text" value="{{ $oldRole ?? '' }}"
                               class="w-full border border-stone-300 rounded-lg px-3.5 py-2.5 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100"
                               placeholder="CFO · Finance Manager · Accountant…">
                    </div>
                </div>

                <div class="pt-2 text-[11px] text-slate-500 leading-relaxed">
                    By continuing you agree we may follow up about your interest in the BMS. We don't share your details with third parties.
                    The sandbox is a private copy — your edits are visible only to you and reset when the session ends.
                </div>

                <div class="flex items-center justify-between pt-2">
                    <a href="/demo" class="text-[12px] text-slate-500 hover:text-slate-900">← Cancel</a>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-[13px] font-semibold text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 shadow transition">
                        Open my sandbox
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 text-center text-[11px] text-slate-500">
            <span class="mono">demo · fictional data · 2-hour session</span>
        </div>
    </div>
</body>
</html>
