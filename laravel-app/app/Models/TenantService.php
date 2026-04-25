<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant service flag — one row per (tenant, service_key).
 *
 * Services are the top-level offerings of EAMS (Business Intelligence,
 * Tax Compliance, Financial Reporting, Financial Planning). Disabling a
 * service short-circuits every module it owns regardless of per-module
 * flags. Toggled by super admins; consumed by the EnsureModuleEnabled
 * middleware and the sidebar nav filter.
 */
class TenantService extends Model
{
    protected $fillable = [
        'tenant_id', 'service_key', 'enabled', 'enabled_at', 'enabled_by',
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
