<?php

namespace Tests\Feature\Admin;

use App\Mail\WelcomeUserMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Welcome-email regression coverage for the super-admin "create user" flow.
 *
 * The bug we're locking down: when a super admin creates a new user via
 * /admin/users/create with the "Email these credentials" box checked,
 * the WelcomeUserMail mailable MUST be dispatched to the new user's
 * address with the plaintext password that was submitted in the form.
 *
 * We exercise the real HTTP controller (POST /admin/users) — not a unit
 * test of the mailable — so any future regression in the controller's
 * dispatch path (e.g. someone moves the Mail::send call inside a feature
 * flag, or removes the checkbox parsing) is caught here.
 *
 * Test setup notes:
 *   - We use Mail::fake() so no real SMTP/log driver writes happen.
 *     phpunit.xml already pins MAIL_MAILER=array as a belt-and-braces
 *     fallback so even un-faked tests can't leak email.
 *   - We seed a super admin and log them in via the admin session key
 *     'admin_user_id' (set by AdminAuthController on real login). The
 *     RequireAdminLogin middleware reads that key.
 *   - We use RefreshDatabase so each test starts clean. Migrations run
 *     against the configured test DB (sqlite by default — see phpunit.xml
 *     commented hint to set DB_CONNECTION=sqlite + DB_DATABASE=:memory:
 *     if the dev DB is something else).
 */
class UserCreationWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed a super admin so the admin session passes RequireAdminLogin.
        $this->superAdmin = User::create([
            'tenant_id' => null,
            'name'      => 'Super Admin',
            'email'     => 'super@example.test',
            'password'  => Hash::make('super-pass-123'),
            'role'      => User::ROLE_SUPER_ADMIN,
            'status'    => User::STATUS_ACTIVE,
        ]);

        // Seed a tenant so we can also test the tenant-user path.
        $this->tenant = Tenant::create([
            'slug'   => 'acme',
            'name'   => 'Acme Co',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    /**
     * Happy path: super admin creates a tenant user with the welcome-email
     * checkbox checked → WelcomeUserMail is dispatched to that user with
     * the exact plaintext password submitted in the form.
     */
    public function test_welcome_email_is_sent_when_checkbox_is_checked(): void
    {
        Mail::fake();

        $plain = 'Temp-Pass-2026!';

        $response = $this->withAdminSession()->post('/admin/users', [
            'tenant_id'           => (string) $this->tenant->id,
            'name'                => 'Jane Newuser',
            'email'               => 'jane@acme.test',
            'password'            => $plain,
            'role'                => User::ROLE_MANAGER,
            'status'              => User::STATUS_ACTIVE,
            'send_welcome_email'  => '1',
        ]);

        $response->assertRedirect('/admin/users');

        // The new user actually got persisted.
        $newUser = User::where('email', 'jane@acme.test')->firstOrFail();
        $this->assertSame($this->tenant->id, $newUser->tenant_id);
        $this->assertSame(User::ROLE_MANAGER, $newUser->role);

        // Mailable dispatched exactly once, to the new user's address,
        // with the exact plaintext password the admin submitted.
        Mail::assertSent(WelcomeUserMail::class, function (WelcomeUserMail $mail) use ($newUser, $plain) {
            return $mail->hasTo($newUser->email)
                && $mail->user->is($newUser)
                && $mail->plainPassword === $plain;
        });
        Mail::assertSentCount(1);
    }

    /**
     * Negative path: when the admin unchecks the "Email these credentials"
     * box, the form omits `send_welcome_email` entirely. No mail should
     * be dispatched in that case — the credentials are shared via the
     * one-time on-screen banner only.
     */
    public function test_welcome_email_is_not_sent_when_checkbox_is_unchecked(): void
    {
        Mail::fake();

        $response = $this->withAdminSession()->post('/admin/users', [
            'tenant_id' => (string) $this->tenant->id,
            'name'      => 'Quiet Newuser',
            'email'     => 'quiet@acme.test',
            'password'  => 'AnotherTemp-2026!',
            'role'      => User::ROLE_VIEWER,
            'status'    => User::STATUS_ACTIVE,
            // send_welcome_email intentionally absent — HTML unchecked
            // checkboxes don't post a value.
        ]);

        $response->assertRedirect('/admin/users');
        $this->assertDatabaseHas('users', ['email' => 'quiet@acme.test']);

        Mail::assertNothingSent();
    }

    /**
     * Platform super-admin path: when `tenant_id=platform` is selected and
     * the welcome email is requested, the mailable still dispatches and
     * the login URL points at /admin/login (not /t/{slug}/login).
     */
    public function test_welcome_email_uses_admin_login_url_for_platform_users(): void
    {
        Mail::fake();

        $this->withAdminSession()->post('/admin/users', [
            'tenant_id'          => 'platform',
            'name'               => 'Newer Admin',
            'email'              => 'newer-admin@example.test',
            'password'           => 'Platform-Temp-9!',
            'role'               => User::ROLE_SUPER_ADMIN,
            'status'             => User::STATUS_ACTIVE,
            'send_welcome_email' => '1',
        ])->assertRedirect('/admin/users');

        Mail::assertSent(WelcomeUserMail::class, function (WelcomeUserMail $mail) {
            return $mail->hasTo('newer-admin@example.test')
                && str_ends_with($mail->loginUrl ?? '', '/admin/login');
        });
    }

    /**
     * Defensive: even if Mail::send() throws (broken SMTP, transient
     * outage), the user must still be persisted and the redirect must
     * still happen. The error gets surfaced to the admin via the flash
     * banner on /admin/users (showing the captured driver error string).
     */
    public function test_user_creation_succeeds_even_if_mail_dispatch_throws(): void
    {
        // Force every dispatch to throw by binding a failing transport.
        // Mail::fake() can't simulate throws, so we swap the mailer to one
        // that explicitly rejects sends.
        Mail::shouldReceive('to')
            ->andReturnSelf();
        Mail::shouldReceive('send')
            ->andThrow(new \RuntimeException('Connection to mail.example.test:587 timed out'));

        $response = $this->withAdminSession()->post('/admin/users', [
            'tenant_id'          => (string) $this->tenant->id,
            'name'               => 'Resilient Newuser',
            'email'              => 'resilient@acme.test',
            'password'           => 'ResilientTemp-2026!',
            'role'               => User::ROLE_ANALYST,
            'status'             => User::STATUS_ACTIVE,
            'send_welcome_email' => '1',
        ]);

        $response->assertRedirect('/admin/users');

        // The user is in the DB — mail failure didn't roll the create back.
        $this->assertDatabaseHas('users', [
            'email' => 'resilient@acme.test',
            'role'  => User::ROLE_ANALYST,
        ]);

        // The flash bag carries the captured error so the index banner
        // can show it. We assert on the structure the controller writes.
        $flash = session('new_user_credentials');
        $this->assertIsArray($flash);
        $this->assertTrue($flash['mail']['attempted'] ?? false);
        $this->assertStringContainsString('timed out', $flash['mail']['error'] ?? '');
    }

    // ──────────────────────────────────────────────────────────────

    /**
     * Helper: returns a test client with the admin session key already
     * populated so RequireAdminLogin lets the request through.
     */
    private function withAdminSession()
    {
        return $this->withSession(['admin_user_id' => $this->superAdmin->id]);
    }
}
