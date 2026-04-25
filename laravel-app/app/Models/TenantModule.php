<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant module flag — one row per (tenant, module_key).
 *
 * The presence of a row with enabled=true means the tenant can access that
 * module. Toggled by super admins; consumed by the EnsureModuleEnabled
 * middleware and the sidebar nav filter.
 */
class TenantModule extends Model
{
    protected $fillable = [
        'tenant_id', 'module_key', 'enabled', 'enabled_at', 'enabled_by',
    ];

    protected $casts = [
        'enabled'    => 'bool',
        'enabled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
