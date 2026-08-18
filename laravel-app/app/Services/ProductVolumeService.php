<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Fetches monthly scanned volumes per product for the Product Revenue TSD
 * sub-page.
 *
 * Delegates the heavy lifting to {@see OperationsService::scanRevenueBreakdown},
 * which already implements the canonical Odoo queries (scanned containers
 * + scanned vehicles + scanned bois truckloads, all anchored on
 * scanned_date with the right tariff grid) and is the source of truth
 * driving the production /operations page. Calling that proven code path
 * twelve times (once per month) is bulletproof and matches the volumes
 * the operations team already sees, so the two pages stay in lockstep.
 *
 * History note: an earlier implementation issued a single
 * Odoo `read_group(date:month)` call per product. That was fast but
 * fragile — when the response shape didn't match the parser, every row
 * was silently dropped and the chart rendered with zero actuals. The
 * delegated approach below trades ~10× more network round-trips for
 * complete robustness, and the cache layer absorbs the cost after the
 * first hit per tenant per year.
 *
 * Products flagged `actuals_source = 'pending'` in config/budget_products.php
 * are still emitted but with an all-null vector — the view renders the
 * budget line alone in that case.
 */
class ProductVolumeService
{
    private OperationsService $ops;

    public function __construct(OdooClient $odoo)
    {
        $this->ops = new OperationsService($odoo);
    }

    /**
     * Monthly scan volumes for every product in the registry.
     *
     * @return array<string, array<int, ?int>>  ['product_key' => [1 => 142, 2 => 138, ...]]
     *                                          null entries for pending products
     */
    public function monthlyVolumes(int $year): array
    {
        $registry = (array) config('budget_products.products', []);

        // Initial state: pending products get nulls (no Odoo source),
        // tracked products get zeros that we'll add into as we walk
        // through the months.
        $result = [];
        foreach ($registry as $product) {
            $key = (string) $product['key'];
            $pending = (($product['actuals_source'] ?? 'pending') !== 'odoo');
            $result[$key] = array_fill(1, 12, $pending ? null : 0);
        }

        // Index: product_key → ops_key (only for products that read from
        // OperationsService::scanRevenueBreakdown). Anything without an
        // ops_key stays at the initial state (nulls or zeros).
        $opsMap = [];
        foreach ($registry as $product) {
            $opsKey = $product['ops_key'] ?? null;
            if ($opsKey) {
                $opsMap[(string) $opsKey] = (string) $product['key'];
            }
            // Extra ops categories folding into the same product. Exists for
            // vehicles_unclassified -> cars: scanned vehicles with no
            // cylinders value are real cars (analyzer PR #37/39 sampled
            // TOYOTA Corolla / RAV / Yaris) and the Billing "Expected" figure
            // already prices them at the Voiture rate - leaving them out of
            // the page's Cars volume made scanned revenue run below Billing
            // by exactly their value (33.15M in Aug 2026, gate scancheck).
            foreach ((array) ($product['ops_keys_extra'] ?? []) as $extra) {
                $opsMap[(string) $extra] = (string) $product['key'];
            }
        }

        // 12 monthly scanRevenueBreakdown calls. Each call returns the
        // full category breakdown for that month; we copy each category's
        // count into the matching product's monthly slot.
        for ($m = 1; $m <= 12; $m++) {
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $m));
            $to   = $from->modify('last day of this month')->setTime(23, 59, 59);

            try {
                $breakdown = $this->ops->scanRevenueBreakdown($from, $to);
            } catch (\Throwable $e) {
                // Per-month failure: leave that month's actuals at 0; keep going.
                continue;
            }
            if (!empty($breakdown['error'])) {
                continue;
            }

            foreach ((array) ($breakdown['categories'] ?? []) as $opsKey => $row) {
                if (!isset($opsMap[(string) $opsKey])) continue;
                $productKey = $opsMap[(string) $opsKey];
                // += because several ops categories can feed one product
                // (voiture + vehicles_unclassified both land in cars).
                $result[$productKey][$m] = (int) ($result[$productKey][$m] ?? 0) + (int) ($row['count'] ?? 0);
            }
        }

        return $result;
    }
}
