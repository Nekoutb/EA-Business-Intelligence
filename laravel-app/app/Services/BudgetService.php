<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantBudget;

/**
 * Read / write per-tenant P&L budgets.
 *
 * Budgets are stored line-level (revenue / direct_costs / opex /
 * depreciation / net_financial / income_tax) for 12 months. The
 * PLForecastService aggregates from accounts up to lines and
 * compares against this when present.
 */
final class BudgetService
{
    /** Canonical line keys the budget supports — matches PLForecastService rows. */
    public const LINE_KEYS = [
        'revenue',
        'direct_costs',
        'opex',
        'depreciation',
        'net_financial',
        'income_tax',
    ];

    /** Display labels (canonical) used by the upload template + parser. */
    public const LINE_LABELS = [
        'revenue'        => 'Revenue',
        'direct_costs'   => 'Direct operating costs',
        'opex'           => 'Operating expenses',
        'depreciation'   => 'Depreciation & amortisation',
        'net_financial'  => 'Net financial result',
        'income_tax'     => 'Income tax',
    ];

    /**
     * Load the budget for a (tenant, year) into the internal shape:
     *   ['revenue' => [1 => 45000000, 2 => ..., ..., 12 => ...], ...]
     *
     * Returns null when no budget has been uploaded yet.
     *
     * @return ?array<string, array<int, float>>
     */
    public function load(int $tenantId, int $year): ?array
    {
        $row = TenantBudget::where('tenant_id', $tenantId)
            ->where('year', $year)
            ->first();
        if (!$row) return null;

        $raw = (array) ($row->data ?? []);
        $out = [];
        foreach (self::LINE_KEYS as $key) {
            $monthly = (array) ($raw[$key] ?? []);
            $vec = [];
            for ($m = 1; $m <= 12; $m++) {
                $vec[$m] = (float) ($monthly[(string) $m] ?? $monthly[$m] ?? 0);
            }
            $out[$key] = $vec;
        }
        return $out;
    }

    /**
     * Persist the parsed budget for a (tenant, year). Upsert by the
     * unique (tenant_id, year) constraint.
     *
     * @param array<string, array<int, float>> $data
     */
    public function save(Tenant $tenant, int $year, array $data, ?string $filename = null, ?int $userId = null): TenantBudget
    {
        // Normalise — keep only known line keys, coerce months 1..12.
        $clean = [];
        foreach (self::LINE_KEYS as $key) {
            $vec = [];
            $src = $data[$key] ?? [];
            for ($m = 1; $m <= 12; $m++) {
                $vec[$m] = (float) ($src[$m] ?? $src[(string) $m] ?? 0);
            }
            $clean[$key] = $vec;
        }

        return TenantBudget::updateOrCreate(
            ['tenant_id' => $tenant->id, 'year' => $year],
            [
                'data'            => $clean,
                'source_filename' => $filename,
                'uploaded_by'     => $userId,
            ]
        );
    }

    /**
     * Delete a tenant's budget for a year (e.g. user wants to start over).
     */
    public function delete(int $tenantId, int $year): void
    {
        TenantBudget::where('tenant_id', $tenantId)
            ->where('year', $year)
            ->delete();
    }

    /**
     * Summary metadata for the upload page (year, source filename,
     * uploader, when, monthly totals per line).
     */
    public function summary(int $tenantId, int $year): ?array
    {
        $row = TenantBudget::with('uploader')
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->first();
        if (!$row) return null;

        $totals = [];
        foreach (self::LINE_KEYS as $key) {
            $vec = (array) ($row->data[$key] ?? []);
            $totals[$key] = array_sum($vec);
        }

        return [
            'year'             => (int) $row->year,
            'source_filename'  => (string) ($row->source_filename ?? ''),
            'uploaded_by_name' => $row->uploader?->name ?? '—',
            'uploaded_at'      => $row->updated_at?->toIso8601String(),
            'totals'           => $totals,
            'data'             => $row->data,
        ];
    }
}
