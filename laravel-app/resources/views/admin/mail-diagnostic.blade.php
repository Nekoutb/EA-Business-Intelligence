@extends('admin.layout')
@section('title', 'Mail diagnostic')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Mail diagnostic</h1>
        <p class="text-xs text-slate-500 mt-1">
            Verify the configured mail driver actually delivers messages. The same <code class="bg-slate-100 px-1 rounded text-[10.5px]">WelcomeUserMail</code> mailable used when admins create new users is dispatched here — if this test arrives in your inbox, the welcome-email flow on <a href="/admin/users/create" class="text-indigo-600 underline">/admin/users/create</a> works end-to-end.
        </p>
    </div>
@endsection

@section('content')

{{-- ─────── Last test result banner ─────── --}}
@if ($lastResult)
    @php $r = $lastResult; @endphp
    <div class="mb-4 panel p-4 border-l-4 {{ $r['sent'] ? 'border-emerald-400 bg-emerald-50/30' : 'border-rose-400 bg-rose-50/30' }}">
        <div class="flex items-start gap-3">
            @if ($r['sent'])
                <svg class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <div class="text-sm text-slate-700">
                    <strong class="text-emerald-700">Test message dispatched</strong>
                    via <code class="bg-white border border-emerald-200 px-1 rounded text-[11px]">{{ $r['driver'] }}</code> to
                    <code class="bg-white border border-emerald-200 px-1 rounded text-[11px]">{{ $r['to'] }}</code>
                    in {{ $r['elapsed_ms'] }} ms.
                    @if ($r['driver'] === 'log')
                        <p class="text-[11px] text-amber-700 mt-1.5">
                            Heads-up: driver is <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">log</code> — the rendered email was written to <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">storage/logs/laravel.log</code> instead of being delivered. Set <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">MAIL_MAILER=smtp</code> in <code class="bg-amber-50 border border-amber-200 px-1 rounded text-[10.5px]">.env</code> and provide SMTP credentials for actual delivery.
                        </p>
                    @else
                        <p class="text-[11px] text-slate-500 mt-1.5">
                            Check the recipient inbox (including spam/promotions). If it didn't arrive, the SMTP provider accepted the message but failed silently after delivery — inspect provider logs.
                        </p>
                    @endif
                </div>
            @else
                <svg class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                <div class="text-sm text-slate-700">
                    <strong class="text-rose-700">Test message FAILED</strong>
                    via <code class="bg-white border border-rose-200 px-1 rounded text-[11px]">{{ $r['driver'] }}</code> to
                    <code class="bg-white border border-rose-200 px-1 rounded text-[11px]">{{ $r['to'] }}</code>
                    after {{ $r['elapsed_ms'] }} ms.
                    <pre class="mt-2 text-[11px] text-rose-700 bg-white border border-rose-200 rounded p-2 whitespace-pre-wrap font-mono">{{ $r['error'] }}</pre>
                    <p class="text-[11px] text-slate-500 mt-1.5">
                        Common causes: bad SMTP credentials, wrong port (587 with STARTTLS / 465 with SSL / 25 plaintext), firewall blocking outbound, <code class="bg-slate-100 px-1 rounded text-[10.5px]">MAIL_FROM_ADDRESS</code> not allowed by your provider, or DNS not resolving the host.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- ─────── Current config snapshot ─────── --}}
<div class="panel p-5 mb-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Current mail configuration</h2>
    <p class="text-[11px] text-slate-500 mb-3">
        Read from <code class="bg-slate-100 px-1 rounded text-[10.5px]">config/mail.php</code>, which reads from <code class="bg-slate-100 px-1 rounded text-[10.5px]">.env</code>. To change these values: edit <code class="bg-slate-100 px-1 rounded text-[10.5px]">.env</code> on the server, then run <code class="bg-slate-100 px-1 rounded text-[10.5px]">php artisan config:clear</code>.
    </p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-[12px]">
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">Driver</span>
            <span class="font-mono font-semibold {{ $config['driver'] === 'log' ? 'text-amber-700' : 'text-emerald-700' }}">{{ $config['driver'] }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">Transport</span>
            <span class="font-mono text-slate-700">{{ $config['transport'] }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">SMTP host</span>
            <span class="font-mono text-slate-700">{{ $config['host'] }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">SMTP port</span>
            <span class="font-mono text-slate-700">{{ $config['port'] }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">Encryption / scheme</span>
            <span class="font-mono text-slate-700">{{ $config['scheme'] ?: '—' }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">SMTP username</span>
            <span class="font-mono text-slate-700">{{ $config['username'] ?: '—' }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">SMTP password set?</span>
            <span class="font-mono {{ $config['has_password'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $config['has_password'] ? 'yes' : 'no' }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">From address</span>
            <span class="font-mono text-slate-700">{{ $config['from_address'] }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 py-1">
            <span class="text-slate-500">From name</span>
            <span class="font-mono text-slate-700">{{ $config['from_name'] }}</span>
        </div>
    </div>

    @if ($config['driver'] === 'log')
        <div class="mt-4 p-3 rounded bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
            <strong>Driver is "log" — emails are NOT delivered.</strong>
            They are written to <code class="bg-white border border-amber-200 px-1 rounded text-[10.5px]">storage/logs/laravel.log</code> instead. Update <code class="bg-white border border-amber-200 px-1 rounded text-[10.5px]">.env</code> on the server to enable real delivery:
            <pre class="mt-2 bg-white border border-amber-200 rounded p-2 text-[11px] text-slate-700 font-mono whitespace-pre overflow-x-auto">MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com         # or your provider's host
MAIL_PORT=587                         # 587 = STARTTLS · 465 = SSL · 25 = plain
MAIL_USERNAME=postmaster@yourdomain   # SMTP login
MAIL_PASSWORD=•••••••••              # SMTP password / app password
MAIL_SCHEME=tls                       # tls (587) or ssl (465)
MAIL_FROM_ADDRESS=no-reply@yourdomain
MAIL_FROM_NAME="Elite Advisors BMS"</pre>
            Then on the server: <code class="bg-white border border-amber-200 px-1 rounded text-[10.5px]">php artisan config:clear</code>.
        </div>
    @endif
</div>

{{-- ─────── Send test ─────── --}}
<div class="panel p-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-2">Send a test message</h2>
    <p class="text-[11px] text-slate-500 mb-3">
        Renders the real welcome-email template (subject, body, login link) and dispatches it through the configured driver. The "password" line in the message is replaced with a placeholder so nothing sensitive is exposed.
    </p>
    <form method="POST" action="/admin/mail-diagnostic" class="flex flex-col sm:flex-row gap-2 items-stretch sm:items-end">
        @csrf
        <div class="flex-1">
            <label class="block text-xs font-medium text-slate-600 mb-1">Send to</label>
            <input name="to" type="email" required value="{{ old('to', $defaultTo) }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono">
            <p class="text-[10px] text-slate-500 mt-1">Defaults to your admin email so you can verify the round-trip immediately.</p>
        </div>
        <button type="submit"
                class="text-[12px] font-semibold text-white bg-gradient-to-r from-indigo-600 to-emerald-600 hover:from-indigo-700 hover:to-emerald-700 rounded-lg px-4 py-2 shadow whitespace-nowrap">
            Send test email
        </button>
    </form>

    @if (isset($errors) && $errors->any())
        <div class="mt-3 p-3 rounded border border-rose-200 bg-rose-50 text-rose-800 text-[12px]">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif
</div>

@endsection
