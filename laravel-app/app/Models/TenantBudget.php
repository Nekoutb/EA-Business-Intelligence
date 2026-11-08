<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant per-year budget uploaded by a tenant user.
 *
 * Read by PLForecastService when assembling the Budget column on the
 * MTD / YTD / Full-Year views. Falls back to PY × growth factor in
 * absence.
 */
class TenantBudget extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    protected $fillable = [
        'tenant_id', 'year', 'data', 'source_filename', 'uploaded_by',
    ];

    protected $casts = [
        'data' => 'array',
        'year' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
