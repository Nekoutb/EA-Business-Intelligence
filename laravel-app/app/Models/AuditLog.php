<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log. Records sensitive events from the super-admin and
 * tenant settings flows: tenant creation, module toggles, impersonation,
 * settings changes, suspensions, etc.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'target', 'payload', 'ip', 'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience: record an audit entry with sensible defaults from the
     * current request. Safe to call from controllers/services.
     */
    public static function record(string $action, array $opts = []): self
    {
        $req = request();
        return self::create([
            'tenant_id'  => $opts['tenant_id'] ?? null,
            'user_id'    => $opts['user_id']   ?? optional($req?->user())->id,
            'action'     => $action,
            'target'     => $opts['target']    ?? null,
            'payload'    => $opts['payload']   ?? null,
            'ip'         => $opts['ip']        ?? $req?->ip(),
            'user_agent' => $opts['user_agent']?? substr((string) $req?->userAgent(), 0, 255),
        ]);
    }
}
