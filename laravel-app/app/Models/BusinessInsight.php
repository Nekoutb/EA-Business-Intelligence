<?php

namespace App\Models;

use App\Services\BusinessInsightsScorer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (tenant, day) snapshot of Claude-generated business
 * insights. The Platform Admin dashboard renders the latest row per
 * tenant. The /admin/business-insights/{tenant} detail page renders
 * the full markdown body plus the underlying KPI snapshot.
 */
class BusinessInsight extends Model
{
    use \App\Models\Concerns\OnTenantDatabase;

    use HasFactory;

    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_WATCH  = 'watch';
    public const PRIORITY_GOOD   = 'good';

    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    // PARTIAL means: the live Odoo fetch couldn't get every block, so
    // BusinessInsightsService composed the snapshot from the latest
    // OK row's `kpis_snapshot` (stale-but-usable). The narrative is
    // still generated, but the row is marked so the admin can spot
    // when stale data is in play.
    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'tenant_id', 'generated_at', 'model',
        'kpis_snapshot', 'insight_md', 'summary',
        'priority', 'input_tokens', 'output_tokens',
        'status', 'error_msg',
    ];

    protected function casts(): array
    {
        return [
            'generated_at'  => 'datetime',
            'kpis_snapshot' => 'array',
            'input_tokens'  => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Latest row per tenant — the one rendered on the admin dashboard. */
    public static function latestForTenant(int $tenantId): ?self
    {
        return self::where('tenant_id', $tenantId)
            ->orderByDesc('generated_at')
            ->first();
    }

    /** Visual styling helpers for the card chrome. */
    public function priorityBadge(): array
    {
        return match ($this->priority) {
            self::PRIORITY_URGENT => ['Urgent', 'bg-rose-50 text-rose-700 border-rose-200'],
            self::PRIORITY_WATCH  => ['Watch',  'bg-amber-50 text-amber-700 border-amber-200'],
            self::PRIORITY_GOOD   => ['Good',   'bg-emerald-50 text-emerald-700 border-emerald-200'],
            default               => ['—',      'bg-slate-50 text-slate-600 border-slate-200'],
        };
    }

    /**
     * Resolve the scorecard for this row's snapshot. Newly-generated rows
     * carry `scorecard` baked into `kpis_snapshot` (set by the service
     * during generate()). Older rows pre-date that wiring — re-compute
     * on the fly so the index/show views uniformly render the KPI strip
     * and risk register without needing to regenerate every historical
     * insight. The computation is pure / deterministic, so callers can
     * also cache this if it becomes a hot path.
     */
    public function scorecard(): array
    {
        $snap = is_array($this->kpis_snapshot) ? $this->kpis_snapshot : [];
        if (is_array($snap['scorecard'] ?? null)) return $snap['scorecard'];
        return BusinessInsightsScorer::score($snap);
    }
}
