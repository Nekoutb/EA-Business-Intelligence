<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeUserMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Admin mail diagnostic — quickly verify the configured mail driver is
 * actually delivering messages without having to create a throwaway
 * user account.
 *
 * Why this exists: the welcome-email path on /admin/users/create depends
 * on Laravel's mail config (.env's MAIL_MAILER + SMTP creds on Hostinger).
 * The default driver is `log`, which silently writes rendered mail to
 * storage/logs/laravel.log instead of delivering it — leading to the
 * symptom "I created a user but they never got their email."
 *
 * This page:
 *   1. Shows the current mail config (driver, host, port, encryption,
 *      from-address) — surfaces config issues at a glance.
 *   2. Lets the admin send a test welcome-style email to ANY address
 *      (defaults to their own admin email). The message rendering path
 *      is the EXACT same Mailable used in production user creation, so
 *      a passing test here = a passing welcome email there.
 *   3. Captures send errors verbatim so SMTP misconfig (auth failure,
 *      connection refused, TLS mismatch) shows up immediately instead
 *      of having to grep the log file.
 *
 * Audit-logged so every test send is recorded ('admin.mail.test_sent'
 * or 'admin.mail.test_failed').
 */
class MailDiagnosticController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.mail-diagnostic', $this->commonViewData($request) + [
            'active' => 'mail_diagnostic',
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:191'],
        ]);

        $admin = $request->attributes->get('admin_user');
        $driver = config('mail.default');
        $result = [
            'sent'    => false,
            'driver'  => $driver,
            'to'      => $data['to'],
            'error'   => null,
            'started' => microtime(true),
        ];

        // Use the real WelcomeUserMail with a synthesised dummy user so
        // the message rendered here is byte-identical (modulo the test
        // recipient) to what a new user would receive. The "user" passed
        // in is in-memory only — never saved.
        $dummy = new User([
            'name'      => 'Mail Diagnostic Test',
            'email'     => $data['to'],
            'role'      => User::ROLE_VIEWER,
            'tenant_id' => null,
        ]);

        try {
            Mail::to($data['to'])->send(new WelcomeUserMail(
                $dummy,
                '••• DIAGNOSTIC •••',
                url('/admin/login'),
            ));
            $result['sent'] = true;

            AuditLog::record('admin.mail.test_sent', [
                'target'  => "mail:{$data['to']}",
                'payload' => [
                    'driver' => $driver,
                    'by'     => $admin?->email,
                ],
            ]);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            Log::warning('Mail diagnostic test send failed', [
                'to'    => $data['to'],
                'error' => $e->getMessage(),
            ]);
            AuditLog::record('admin.mail.test_failed', [
                'target'  => "mail:{$data['to']}",
                'payload' => [
                    'driver' => $driver,
                    'error'  => $e->getMessage(),
                    'by'     => $admin?->email,
                ],
            ]);
        }

        $result['elapsed_ms'] = (int) round((microtime(true) - $result['started']) * 1000);

        return redirect('/admin/mail-diagnostic')->with('mail_test_result', $result);
    }

    /**
     * Shape the read-only config snapshot the view renders at the top of
     * the page. Hides the password but reveals everything else so admins
     * can sanity-check the .env values without SSH'ing into the box.
     */
    private function commonViewData(Request $request): array
    {
        $admin = $request->attributes->get('admin_user');

        $driver  = config('mail.default');
        $mailer  = config("mail.mailers.{$driver}", []);
        $from    = config('mail.from', []);

        $config = [
            'driver'        => $driver,
            'transport'     => $mailer['transport'] ?? '—',
            'host'          => $mailer['host']      ?? '—',
            'port'          => $mailer['port']      ?? '—',
            'scheme'        => $mailer['scheme']    ?? '—',
            'username'      => $mailer['username']  ?? '—',
            // Reveal whether a password is set, but never the value.
            'has_password'  => !empty($mailer['password']),
            'from_address'  => $from['address']     ?? '—',
            'from_name'     => $from['name']        ?? '—',
            'log_channel'   => $mailer['channel']   ?? null,
        ];

        // Default the "send to" field to the current admin's email so
        // one click runs a round-trip test back to themselves.
        $defaultTo = $admin?->email ?? config('mail.from.address');

        return [
            'config'     => $config,
            'defaultTo'  => $defaultTo,
            'lastResult' => session('mail_test_result'),
        ];
    }
}
