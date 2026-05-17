<?php

namespace App\Models\PostRetirementBenefits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-overridable Collective Bargaining Agreement.
 *
 * The actual bracket tables live in JSON columns matching the
 * config/post_retirement_cbas.php shape. CbaRegistry::lookup()
 * checks this table first and falls back to the config registry,
 * so out-of-the-box every tenant sees the platform-default CBAs
 * (Commerce, Statutory Minimum) without needing any DB seed.
 *
 * The table is needed for two cases:
 *   1. A sector CBA renegotiation (new brackets) without a code
 *      release.
 *   2. A tenant with a non-standard CBA (custom industry, internal
 *      labor agreement) that doesn't match any platform default.
 */
class Cba extends Model
{
    protected $table = 'post_retirement_cbas';

    protected $fillable = [
        'tenant_id', 'key', 'name', 'source_doc', 'min_service_months',
        'notice_period_months', 'termination_brackets',
        'retirement_brackets', 'death_brackets', 'notes',
    ];

    protected $casts = [
        'notice_period_months' => 'array',
        'termination_brackets' => 'array',
        'retirement_brackets'  => 'array',
        'death_brackets'       => 'array',
        'notes'                => 'array',
        'min_service_months'   => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'cba_id');
    }
}
