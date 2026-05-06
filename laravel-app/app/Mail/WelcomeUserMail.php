<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-shot welcome email sent to a newly-created user with their
 * initial credentials + a deep-link to their tenant login page.
 *
 * Usage:
 *   Mail::to($user->email)->send(new WelcomeUserMail($user, $plainPassword));
 *
 * Renders a Markdown view (resources/views/mail/welcome-user.blade.php)
 * so it inherits Laravel's default mail layout (header/footer, dark-mode
 * support, table-based fallback). The plaintext password is bound as a
 * view variable — never persisted, only kept in memory long enough to
 * render the message.
 *
 * The default mail driver in this app is `log`; if SMTP isn't set up
 * the rendered message lands in storage/logs/laravel.log so the admin
 * can copy the credentials from there. Once MAIL_MAILER=smtp is
 * configured + SMTP creds are in .env, this same Mailable starts
 * shipping for real with no code change.
 */
class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
        public ?string $loginUrl = null,
    ) {
        // Compute a sensible default login URL based on whether the
        // user is platform-level (super admin → /admin/login) or tenant
        // (→ /t/{slug}/login).
        if ($this->loginUrl === null) {
            $this->loginUrl = $user->tenant_id
                ? url('/t/' . optional($user->tenant)->slug . '/login')
                : url('/admin/login');
        }
    }

    public function envelope(): Envelope
    {
        $brand = $this->user->tenant?->brand ?? config('app.name', 'BMS');
        return new Envelope(
            subject: "Welcome to {$brand} — your login details",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.welcome-user',
            with: [
                'name'      => $this->user->name,
                'email'     => $this->user->email,
                'password'  => $this->plainPassword,
                'role'      => $this->user->role,
                'loginUrl'  => $this->loginUrl,
                'tenantName'=> $this->user->tenant?->name,
                'brand'     => $this->user->tenant?->brand ?? config('app.name', 'BMS'),
            ],
        );
    }
}
