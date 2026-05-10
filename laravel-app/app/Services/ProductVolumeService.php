<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Fetches monthly scanned volumes per product for the Product Revenue TSD
 * sub-page. One Odoo `read_group` call per registered product; results are
 * keyed by product slug and bucketed by month (1..12) for the requested
 * calendar year.
 *
 * Products flagged `actuals_source = 'pending'` in config/budget_products.php
 * skip the round-trip entirely and return an all-null vector — the view
 * renders the budget line alone in that case.
 *
 * Each Odoo call is wrapped in try/catch; a model that isn't installed
 * (or a permission error) degrades to all-nulls for that one product
 * rather than taking the whole page down.
 */
final class ProductVolumeService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * Monthly scan volumes for every product in the registry.
     *
     * @return array<string, array<int, ?int>>  ['product_key' => [1 => 142, 2 => 138, ...]]
     *                                          null entries when the product is pending Odoo
     */
    public function monthlyVolumes(int $year): array
    {
        $from = new \DateTimeImmutable("{$year}-01-01 00:00:00");
        $to   = new \DateTimeImmutable("{$year}-12-31 23:59:59");

        $result = [];
        foreach ((array) config('budget_products.products', []) as $product) {
            $key = (string) $product['key'];
            $source = (string) ($product['actuals_source'] ?? 'pending');

            if ($source !== 'odoo' || empty($product['odoo'])) {
                // Budget-only — keep the slot, signal "no data" with all nulls.
                $result[$key] = array_fill(1, 12, null);
                continue;
            }

            $result[$key] = $this->fetchMonthlyVolumes((array) $product['odoo'], $from, $to);
        }

        return $result;
    }

    /**
     * One product's monthly volume vector. Returns all-zero (not null) on
     * a successful Odoo call that just returned no rows — the chart line
     * stays at 0 instead of being absent, which is the truthful read for
     * "we tracked it, nothing scanned this month".
     *
     * Returns all-nulls if the Odoo call itself blew up.
     *
     * @return array<int, ?int>
     */
    private function fetchMonthlyVolumes(array $odooCfg, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $model     = (string) $odooCfg['model'];
        $filter    = (array)  ($odooCfg['filter'] ?? []);
        $dateField = (string) ($odooCfg['date_field'] ?? 'create_date');
        $groupBy   = $odooCfg['group_by'] ?? null;   // when set (e.g. wood/cargo_id),
                                                    // we want a distinct count per month.

        $domain = array_merge($filter, [
            [$dateField, '>=', $from->format('Y-m-d H:i:s')],
            [$dateField, '<=', $to->format('Y-m-d H:i:s')],
        ]);

        // Group by the date field at month granularity (Odoo standard syntax).
        // When `group_by` is set, we group by BOTH that field and the month —
        // one row per (group_value, month) combination, so a simple row-count
        // per month gives us the distinct value count we want.
        $groupbyFields = $groupBy
            ? [$groupBy, $dateField . ':month']
            : [$dateField . ':month'];

        try {
            $rows = $this->odoo->callModel($model, 'read_group', [
                'domain'  => $domain,
                'fields'  => [],
                'groupby' => $groupbyFields,
                'lazy'    => false,
            ]);
        } catch (\Throwable $e) {
            // Model unreachable / unknown / permission error — degrade gracefully.
            return array_fill(1, 12, null);
        }

        $monthly = array_fill(1, 12, 0);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $month = $this->extractMonth($row, $dateField);
            if ($month === null) continue;

            if ($groupBy) {
                // Distinct-value count per month: each row represents one
                // (group_value, month) combination so incrementing by 1
                // counts the distinct values.
                $groupVal = $row[$groupBy] ?? null;
                // Odoo many2one returns [id, display_name] or false.
                if (is_array($groupVal)) {
                    $id = (int) ($groupVal[0] ?? 0);
                    if ($id <= 0) continue;
                } elseif (!$groupVal) {
                    continue;
                }
                $monthly[$month]++;
            } else {
                $count = (int) ($row['__count'] ?? 0);
                $monthly[$month] += $count;
            }
        }

        return $monthly;
    }

    /**
     * Extract the calendar month (1..12) from a read_group bucket row.
     * Uses `__range[date:month]['from']` first because it's
     * locale-independent — the human label ("January 2026" vs "janvier
     * 2026") depends on the Odoo user's lang setting and would break
     * parsing. The bucket label is the fallback.
     */
    private function extractMonth(array $row, string $dateField): ?int
    {
        $rangeKey = $dateField . ':month';

        $range = $row['__range'] ?? null;
        if (is_array($range) && isset($range[$rangeKey]['from'])) {
            try {
                return (int) (new \DateTimeImmutable((string) $range[$rangeKey]['from']))->format('n');
            } catch (\Throwable $e) {
                // fall through to label parsing
            }
        }

        // Fallback: parse the bucket label using common formats.
        $label = $row[$rangeKey] ?? null;
        if (is_string($label) && $label !== '') {
            foreach (['F Y', 'M Y', 'Y-m', 'm/Y'] as $fmt) {
                $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $label);
                if ($d !== false) return (int) $d->format('n');
            }
        }

        return null;
    }
}
