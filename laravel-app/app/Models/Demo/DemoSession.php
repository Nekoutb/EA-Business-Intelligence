<?php

namespace App\Models\Demo;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a Laravel session cookie to a freshly-spawned demo tenant.
 *
 * The middleware (ResolveDemoSession) looks up the row by
 * `session_id = session()->getId()` on every /demo/* request and binds
 * the corresponding tenant to TenantContext, which is then the same
 * context the rest of the BMS services + controllers use.
 *
 * Captures lead info (name + email + company + role) for sales follow-up.
 */
class DemoSession extends Model
{
    protected $fillable = [
        'session_id', 'tenant_id', 'name', 'email', 'phone', 'company', 'role',
        'ip_address', 'user_agent', 'started_at', 'last_seen_at', 'expires_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
