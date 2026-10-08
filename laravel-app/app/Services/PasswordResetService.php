<?php

namespace App\Services;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Password reset for tenant workspace users and platform admins.
 *
 * Tokens: 64 random chars, stored as SHA-256 only, valid 60 minutes,
 * single use; issuing a new token voids the user's earlier ones.
 * Completing a reset ends every session the user has open (database
 * session driver), clears the temporary-password flag, and emails a
 * "your password was changed" notice so a hijacked reset is noticed.
 */
class PasswordResetService
{
    public const TTL_MINUTES = 60;

    /**
     * Issue a token and email the link. Returns false only when mail
     * delivery failed — callers facing the public must not reveal that
     * (or whether the account exists), but admin tooling can.
     */
    public static function sendLink(User $user, string $requestedBy = 'self', ?string $via = null): bool
    {
        // $via: 'admin' for the platform console, otherwise the workspace
        // slug — the link returns the user to the door they knocked on.
        $via ??= $user->tenant_id === null ? 'admin' : (string) $user->tenant?->slug;
        $raw = Str::random(64);
        DB::table('user_password_resets')->where('user_id', $user->id)->whereNull('used_at')->delete();
        DB::table('user_password_resets')->insert([
            'user_id'      => $user->id,
            'token_hash'   => hash('sha256', $raw),
            'requested_by' => $requestedBy,
            'requested_ip' => request()?->ip(),
            'expires_at'   => now()->addMinutes(self::TTL_MINUTES),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $url = $via === 'admin'
            ? url('/admin/reset-password/' . $raw)
            : url('/t/' . $via . '/reset-password/' . $raw);

        AuditLog::record('user.password_reset_requested', [
            'tenant_id' => $user->tenant_id,
            'target'    => "user:{$user->id}",
            'payload'   => ['by' => $requestedBy],
        ]);

        try {
            Mail::to($user->email)->send(new PasswordResetMail($user, $url, self::TTL_MINUTES));
            return true;
        } catch (\Throwable $e) {
            Log::warning('PasswordResetMail dispatch failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** The user a raw token belongs to, or null if invalid/expired/used. */
    public static function userForToken(string $raw): ?User
    {
        if (strlen($raw) !== 64) return null;
        $row = DB::table('user_password_resets')
            ->where('token_hash', hash('sha256', $raw))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
        if (!$row) return null;
        $user = User::find($row->user_id);
        return ($user && $user->status === User::STATUS_ACTIVE) ? $user : null;
    }

    /** Consume the token and set the new password. False if the token is no longer valid. */
    public static function complete(string $raw, string $newPassword): ?User
    {
        $user = self::userForToken($raw);
        if (!$user) return null;

        DB::transaction(function () use ($raw, $user, $newPassword) {
            DB::table('user_password_resets')
                ->where('token_hash', hash('sha256', $raw))
                ->update(['used_at' => now(), 'updated_at' => now()]);
            $user->forceFill([
                'password'             => Hash::make($newPassword),
                'must_change_password' => false,
                'remember_token'       => Str::random(60),
            ])->save();
        });

        self::endSessions($user);

        AuditLog::record('user.password_reset_completed', [
            'tenant_id' => $user->tenant_id,
            'target'    => "user:{$user->id}",
        ]);

        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user));
        } catch (\Throwable $e) {
            Log::warning('PasswordChangedMail dispatch failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
        return $user;
    }

    /** End every open session of this user (sessions table, database driver). */
    public static function endSessions(User $user, ?string $keepSessionId = null): void
    {
        try {
            DB::table('sessions')->where('user_id', $user->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        } catch (\Throwable $e) {
            // Non-database session driver: nothing to purge server-side.
        }
    }
}
