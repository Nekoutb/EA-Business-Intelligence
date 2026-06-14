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
     * Load per-product budgets (Product Revenue TSD sub-page).
     *
     * Reads the `products` key inside tenant_budgets.data, normalising
     * every product slot in config('budget_products.products') so the
     * caller always sees a complete map keyed by product slug. Missing
     * products fall back to defaults from config.
     *
     * Shape returned (always keyed by product slug):
     *   [
     *     'cars' => [
     *       'unit_price' => 50000.0,
     *       'volumes'    => [1 => 3500.0, 2 => 3500.0, ..., 12 => 3500.0],
     *     ],
     *     'trucks' => [...],
     *     ...
     *   ]
     *
     * @return array<string, array{unit_price: float, volumes: array<int, float>}>
     */
    public function loadProducts(int $tenantId, int $year): array
    {
        $row = TenantBudget::where('tenant_id', $tenantId)
            ->where('year', $year)
            ->first();

        $raw = $row ? (array) ($row->data['products'] ?? []) : [];
        $out = [];

        foreach ((array) config('budget_products.products', []) as $product) {
            $key = $product['key'];
            $stored = (array) ($raw[$key] ?? []);
            $volumes = [];
            $src = (array) ($stored['volumes'] ?? []);
            for ($m = 1; $m <= 12; $m++) {
                $volumes[$m] = (float) ($src[$m] ?? $src[(string) $m] ?? 0);
            }
            $out[$key] = [
                'unit_price' => isset($stored['unit_price'])
                    ? (float) $stored['unit_price']
                    : (float) ($product['default_price'] ?? 0),
                'volumes'    => $volumes,
            ];
        }

        return $out;
    }

    /**
     * Persist per-product budgets without disturbing the existing P&L
     * line keys (revenue / direct_costs / etc.). The two halves of the
     * data JSON live independently — uploading a P&L budget never wipes
     * the product budgets, and vice versa.
     *
     * @param array<string, array{unit_price?: numeric, volumes?: array<int|string, numeric>}> $products
     */
    public function saveProducts(Tenant $tenant, int $year, array $products, ?int $userId = null): TenantBudget
    {
        $row = TenantBudget::firstOrNew([
            'tenant_id' => $tenant->id,
            'year'      => $year,
        ]);

        $existing = (array) ($row->data ?? []);
        $clean = [];

        // Only accept keys that exist in the registry — defends against
        // stale form submissions referencing a product that was deleted.
        $registry = collect((array) config('budget_products.products', []))->keyBy('key');

        foreach ($products as $key => $payload) {
            if (!$registry->has($key)) continue;
            $product = $registry[$key];

            $price = (float) ($payload['unit_price'] ?? $product['default_price'] ?? 0);
            $price = max(0.0, $price);

            $volumes = [];
            $src = (array) ($payload['volumes'] ?? []);
            for ($m = 1; $m <= 12; $m++) {
                $volumes[(string) $m] = max(0.0, (float) ($src[$m] ?? $src[(string) $m] ?? 0));
            }

            $clean[$key] = [
                'label'      => (string) $product['label'],
                'category'   => (string) $product['category'],
                'unit_price' => $price,
                'volumes'    => $volumes,
            ];
        }

        $existing['products'] = $clean;
        $row->data = $existing;
        if ($userId) $row->uploaded_by = $userId;
        $row->save();

        return $row;
    }

    /**
     * Load the saved P&L forecast assumptions for a (tenant, year).
     * Stored alongside the budget inside tenant_budgets.data under the
     * 'forecast_assumptions' key — no separate table/migration. Returns
     * null when the tenant hasn't saved any (the service then derives
     * sensible defaults from actuals).
     *
     * @return ?array<string, mixed>
     */
    public function loadAssumptions(int $tenantId, int $year): ?array
    {
        $row = TenantBudget::where('tenant_id', $tenantId)
            ->where('year', $year)
            ->first();
        if (!$row) return null;
        $a = $row->data['forecast_assumptions'] ?? null;
        return is_array($a) ? $a : null;
    }

    /**
     * Persist P&L forecast assumptions without disturbing the line
     * budget or the per-product budget that live in the same data JSON.
     *
     * @param array<string, mixed> $assumptions  already-normalised driver map
     */
    public function saveAssumptions(Tenant $tenant, int $year, array $assumptions, ?int $userId = null): TenantBudget
    {
        $row = TenantBudget::firstOrNew([
            'tenant_id' => $tenant->id,
            'year'      => $year,
        ]);
        $existing = (array) ($row->data ?? []);
        $existing['forecast_assumptions'] = $assumptions;
        $row->data = $existing;
        if ($userId) $row->uploaded_by = $userId;
        $row->save();
        return $row;
    }

    /**
     * Dashboard efficiency-KPI budget targets the user types in on the
     * dashboard's "KPI Targets & Trends" panel. Stored as flat annual
     * targets (one number each) so the dashboard can draw a horizontal
     * budget line across the monthly actual on each KPI trend chart.
     */
    public const KPI_BUDGET_KEYS = [
        'rev_per_scan',
        'cost_per_scan',
        'ebitda_margin',
        'net_margin',
        'dso',
        'dpo',
    ];

    /**
     * Load the KPI budget targets for a (tenant, year). Always returns a
     * complete map keyed by KPI; an unset target is null (no budget line).
     *
     * @return array<string, ?float>
     */
    public function loadKpiBudgets(int $tenantId, int $year): array
    {
        $row = TenantBudget::where('tenant_id', $tenantId)
            ->where('year', $year)
            ->first();
        $raw = $row ? (array) ($row->data['kpi_budgets'] ?? []) : [];

        $out = [];
        foreach (self::KPI_BUDGET_KEYS as $k) {
            $out[$k] = isset($raw[$k]) && is_numeric($raw[$k]) ? (float) $raw[$k] : null;
        }
        return $out;
    }

    /**
     * Persist KPI budget targets without disturbing the line budget,
     * per-product budget, or forecast assumptions that share the data JSON.
     * Only known keys with a numeric value are stored; a blank field
     * clears that target.
     *
     * @param array<string, mixed> $budgets  key => numeric|blank
     */
    public function saveKpiBudgets(Tenant $tenant, int $year, array $budgets, ?int $userId = null): TenantBudget
    {
        $row = TenantBudget::firstOrNew([
            'tenant_id' => $tenant->id,
            'year'      => $year,
        ]);
        $existing = (array) ($row->data ?? []);

        $clean = [];
        foreach (self::KPI_BUDGET_KEYS as $k) {
            if (isset($budgets[$k]) && is_numeric($budgets[$k])) {
                $clean[$k] = (float) $budgets[$k];
            }
        }

        $existing['kpi_budgets'] = $clean;
        $row->data = $existing;
        if ($userId) $row->uploaded_by = $userId;
        $row->save();
        return $row;
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
