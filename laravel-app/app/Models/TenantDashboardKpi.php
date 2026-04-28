<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant on/off flag for one Business Intelligence Dashboard KPI.
 *
 * One row per (tenant, kpi_key). Presence of a row with `enabled=true`
 * means the KPI card is rendered on this tenant's Dashboard page.
 * Absence of a row falls back to the registry default.
 */
class TenantDashboardKpi extends Model
{
    protected $fillable = [
        'tenant_id', 'kpi_key', 'enabled', 'enabled_at', 'enabled_by',
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
