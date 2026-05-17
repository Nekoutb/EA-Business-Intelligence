<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\Cba as CbaModel;
use App\Models\Tenant;

/**
 * Single point of truth for resolving "which CBA applies" + reading
 * its bracket schedule.
 *
 * Lookup order:
 *   1. DB override at tenant scope (post_retirement_cbas where
 *      tenant_id = X and key = Y)
 *   2. DB platform-wide entry (tenant_id = null, key = Y) — used
 *      when a sector CBA gets renegotiated and we patch the
 *      brackets without a code release
 *   3. config/post_retirement_cbas.php — the platform baseline
 *
 * The returned shape is always a normalised array:
 *   [
 *     'key' => 'commerce', 'name' => '...', 'source_doc' => '...',
 *     'min_service_months' => 12,
 *     'notice_period_months' => [...],
 *     'termination_brackets' => [...],
 *     'retirement_brackets'  => [...],
 *     'death_brackets'       => [...],
 *     'notes' => [...],
 *   ]
 *
 * Callers (the PUC engine, the UI, the report exporter) never
 * care where the data came from — that's the registry's job.
 */
final class CbaRegistry
{
    /**
     * Resolve a CBA by key for a given tenant.
     * Returns null if no CBA with that key exists at any level.
     */
    public static function lookup(string $key, ?Tenant $tenant = null): ?array
    {
        // 1. Tenant-scoped DB override
        if ($tenant) {
            $row = CbaModel::where('tenant_id', $tenant->id)
                ->where('key', $key)
                ->first();
            if ($row) {
                return self::fromModel($row);
            }
        }

        // 2. Platform-wide DB override (tenant_id = null)
        $row = CbaModel::whereNull('tenant_id')
            ->where('key', $key)
            ->first();
        if ($row) {
            return self::fromModel($row);
        }

        // 3. Config-file baseline
        $config = config("post_retirement_cbas.{$key}");
        if (is_array($config)) {
            return self::normaliseConfig($key, $config);
        }

        return null;
    }

    /**
     * List every CBA available to the tenant — used to populate
     * dropdowns in the employee editor and the assumption page.
     * Tenant overrides shadow the matching platform key.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listForTenant(?Tenant $tenant = null): array
    {
        $out = [];

        // Start with config-file baseline
        foreach ((array) config('post_retirement_cbas', []) as $key => $cfg) {
            $out[$key] = self::normaliseConfig((string) $key, (array) $cfg);
        }

        // Layer platform-wide DB overrides
        foreach (CbaModel::whereNull('tenant_id')->get() as $row) {
            $out[$row->key] = self::fromModel($row);
        }

        // Layer tenant-scoped overrides
        if ($tenant) {
            foreach (CbaModel::where('tenant_id', $tenant->id)->get() as $row) {
                $out[$row->key] = self::fromModel($row);
            }
        }

        return array_values($out);
    }

    /**
     * Resolve an employee's CBA — tries the DB FK first, falls
     * back to the string key. Returns null if neither resolves.
     */
    public static function forEmployee(\App\Models\PostRetirementBenefits\Employee $e, ?Tenant $tenant = null): ?array
    {
        if ($e->cba_id) {
            $row = CbaModel::find($e->cba_id);
            if ($row) {
                return self::fromModel($row);
            }
        }
        if ($e->cba_key) {
            return self::lookup($e->cba_key, $tenant ?: $e->tenant);
        }
        return null;
    }

    // ── Normalisation helpers ─────────────────────────────────

    private static function fromModel(CbaModel $row): array
    {
        return [
            'key'                  => $row->key,
            'name'                 => $row->name,
            'source_doc'           => $row->source_doc,
            'min_service_months'   => (int) $row->min_service_months,
            'notice_period_months' => $row->notice_period_months ?: [],
            'termination_brackets' => $row->termination_brackets ?: [],
            'retirement_brackets'  => $row->retirement_brackets  ?: [],
            'death_brackets'       => $row->death_brackets       ?: [],
            'notes'                => $row->notes                ?: [],
            'source'               => 'db',
        ];
    }

    private static function normaliseConfig(string $key, array $cfg): array
    {
        return [
            'key'                  => $cfg['key']                  ?? $key,
            'name'                 => $cfg['name']                 ?? $key,
            'source_doc'           => $cfg['source_doc']           ?? null,
            'min_service_months'   => (int) ($cfg['min_service_months'] ?? 12),
            'notice_period_months' => $cfg['notice_period_months'] ?? [],
            'termination_brackets' => $cfg['termination_brackets'] ?? [],
            'retirement_brackets'  => $cfg['retirement_brackets']  ?? [],
            'death_brackets'       => $cfg['death_brackets']       ?? [],
            'notes'                => $cfg['notes']                ?? [],
            'source'               => 'config',
        ];
    }

    /**
     * Apply a bracket schedule to a known seniority in years.
     * Returns the months of salary owed (sum of band_rate × years_in_band).
     *
     * Example: brackets = [
     *   ['from' => 0,  'to' => 5,    'rate' => 0.40],
     *   ['from' => 5,  'to' => 10,   'rate' => 0.45],
     *   ['from' => 10, 'to' => null, 'rate' => 0.60],
     * ]
     * years = 12.5 → 5×0.40 + 5×0.45 + 2.5×0.60 = 5.75 months
     */
    public static function applyBrackets(array $brackets, float $years): float
    {
        if ($years <= 0 || empty($brackets)) {
            return 0.0;
        }
        $months = 0.0;
        foreach ($brackets as $b) {
            $from = (float) ($b['from'] ?? 0);
            $to   = isset($b['to']) && $b['to'] !== null ? (float) $b['to'] : INF;
            $rate = (float) ($b['rate'] ?? 0);
            if ($years <= $from) {
                break;
            }
            $yearsInBand = min($years, $to) - $from;
            if ($yearsInBand > 0) {
                $months += $yearsInBand * $rate;
            }
        }
        return $months;
    }
}
