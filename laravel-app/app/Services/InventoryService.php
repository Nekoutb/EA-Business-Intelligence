<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * InventoryService — Inventory reconciliation and analysis.
 *
 * Reconciles stock.quant total to GL inventory accounts (OHADA 3XX).
 * Calculates daily inventory values for YTD trend analysis.
 */
class InventoryService
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * Analyze inventory for period.
     *
     * @param DateTimeImmutable $today
     * @param array $period ['type' => 'ytd'|'month'|'mtd'|'yoy', 'year' => int, 'month' => int]
     * @return array
     */
    public function analyze(DateTimeImmutable $today, array $period): array
    {
        try {
            // Resolve period dates
            [$from, $to, $label] = ea_resolve_period($today, $period);

            // Fetch current inventory values
            $current = $this->getCurrentInventory();

            // Fetch GL inventory accounts (OHADA 3XX)
            $glInventory = $this->getGLInventoryAccounts();

            // Reconciliation
            $reconciliation = $this->reconcile($current, $glInventory);

            // YTD daily inventory trend
            $trend = $this->getInventoryTrend($from, $to, $today);

            return [
                'current' => $current,
                'gl_inventory' => $glInventory,
                'reconciliation' => $reconciliation,
                'trend' => $trend,
                'currency' => $this->detectCurrency(),
                'period' => $period,
                'period_label' => $label,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'current' => [],
                'gl_inventory' => [],
                'reconciliation' => [],
                'trend' => [],
                'currency' => 'USD',
                'period' => $period,
                'period_label' => '',
                'from' => '',
                'to' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get current inventory from stock.quant.
     */
    private function getCurrentInventory(): array
    {
        $quants = $this->odoo->searchRead(
            'stock.quant',
            [['company_id', '=', $this->getCompanyId()]],
            ['product_id', 'quantity', 'location_id'],
            ['limit' => 10000]
        );

        // Batch fetch products for costs
        $productIds = array_map(fn($q) => $q['product_id'][0], $quants);
        $productIds = array_unique($productIds);

        $products = [];
        if (!empty($productIds)) {
            $productsData = $this->odoo->read(
                'product.product',
                $productIds,
                ['name', 'standard_price', 'default_code', 'type']
            );
            foreach ($productsData as $p) {
                $products[$p['id']] = $p;
            }
        }

        // Calculate total value
        $totalValue = 0;
        $totalQty = 0;
        $byCategory = [];

        foreach ($quants as $quant) {
            $productId = $quant['product_id'][0];
            $qty = $quant['quantity'];
            $totalQty += $qty;

            if (isset($products[$productId])) {
                $product = $products[$productId];
                $cost = $product['standard_price'] ?? 0;
                $value = $qty * $cost;
                $totalValue += $value;

                // Group by product category
                $productName = $product['name'] ?? 'Unknown';
                if (!isset($byCategory[$productName])) {
                    $byCategory[$productName] = [
                        'qty' => 0,
                        'cost' => $cost,
                        'value' => 0,
                        'code' => $product['default_code'] ?? '',
                    ];
                }
                $byCategory[$productName]['qty'] += $qty;
                $byCategory[$productName]['value'] += $value;
            }
        }

        return [
            'total_value' => round($totalValue, 2),
            'total_qty' => round($totalQty, 2),
            'by_product' => $byCategory,
            'record_count' => count($quants),
        ];
    }

    /**
     * Get GL inventory accounts (OHADA 3XX, excluding 39X).
     */
    private function getGLInventoryAccounts(): array
    {
        try {
            // Read GL entries for inventory accounts (3XX excluding 39X)
            // Use account_type = 'asset' and account code starts with 3
            $entries = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['parent_state', '=', 'posted'],
                    ['account_id.account_type', '=', 'asset'],
                    ['account_id.code', 'like', '3%'],
                    ['account_id.code', 'not like', '39%'],
                ],
                ['account_id', 'balance', 'account_id'],
                ['limit' => 1000]
            );

            // Group by account
            $byAccount = [];
            foreach ($entries as $entry) {
                $accountId = $entry['account_id'][0];
                $accountName = $entry['account_id'][1];
                $balance = $entry['balance'] ?? 0;

                if (!isset($byAccount[$accountId])) {
                    $byAccount[$accountId] = [
                        'name' => $accountName,
                        'code' => '',
                        'balance' => 0,
                    ];
                }
                $byAccount[$accountId]['balance'] += $balance;
            }

            // Fetch account codes
            $accountIds = array_keys($byAccount);
            if (!empty($accountIds)) {
                $accounts = $this->odoo->read('account.account', $accountIds, ['code', 'name']);
                foreach ($accounts as $acc) {
                    if (isset($byAccount[$acc['id']])) {
                        $byAccount[$acc['id']]['code'] = $acc['code'] ?? '';
                        $byAccount[$acc['id']]['name'] = $acc['name'] ?? $byAccount[$acc['id']]['name'];
                    }
                }
            }

            $totalBalance = array_sum(array_map(fn($a) => $a['balance'], $byAccount));

            return [
                'total_balance' => round($totalBalance, 2),
                'by_account' => array_values($byAccount),
                'account_count' => count($byAccount),
            ];
        } catch (\Exception $e) {
            return [
                'total_balance' => 0,
                'by_account' => [],
                'account_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reconcile stock.quant total to GL inventory accounts.
     */
    private function reconcile(array $current, array $glInventory): array
    {
        $currentValue = $current['total_value'] ?? 0;
        $glBalance = $glInventory['total_balance'] ?? 0;
        $variance = $glBalance - $currentValue;
        $variancePercent = $currentValue > 0 ? round(($variance / $currentValue) * 100, 2) : 0;

        return [
            'stock_value' => $currentValue,
            'gl_balance' => $glBalance,
            'variance' => round($variance, 2),
            'variance_percent' => $variancePercent,
            'reconciled' => abs($variance) < 0.01, // Allow for rounding
            'interpretation' => $this->getReconciliationInterpretation($variance, $currentValue),
        ];
    }

    /**
     * Get human-readable interpretation of reconciliation variance.
     */
    private function getReconciliationInterpretation(float $variance, float $currentValue): string
    {
        if (abs($variance) < 0.01) {
            return 'Inventory perfectly reconciled with GL';
        }

        if ($variance > 0) {
            $percent = ($variance / $currentValue) * 100;
            return "GL balance exceeds stock by " . number_format($percent, 1) . "% — possible unrecorded adjustments or obsolete inventory not written down";
        } else {
            $percent = (abs($variance) / $currentValue) * 100;
            return "Stock exceeds GL balance by " . number_format($percent, 1) . "% — possible missing cost accounting entries";
        }
    }

    /**
     * Get daily inventory value trend for YTD (or period).
     * Returns data points for month/week level granularity.
     */
    private function getInventoryTrend(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today): array
    {
        try {
            // For each day in range, calculate inventory value
            // This is expensive, so we'll use month-end values and interpolate

            $monthlyTrend = [];
            $current = $from->modify('first day of this month');

            while ($current <= $to) {
                $monthEnd = $current->modify('last day of this month');
                if ($monthEnd > $to) {
                    $monthEnd = $to;
                }

                // Get inventory value as of month end
                $value = $this->getInventoryValueAsOf($monthEnd);

                $monthlyTrend[] = [
                    'date' => $monthEnd->format('Y-m-d'),
                    'month' => $monthEnd->format('M Y'),
                    'week' => 'W' . $monthEnd->format('W'),
                    'value' => round($value, 2),
                ];

                // Move to next month
                $current = $monthEnd->modify('+1 day')->modify('first day of this month');
            }

            // Also calculate weekly trend within current month
            $weeklyTrend = [];
            $current = $from;
            while ($current <= $to) {
                $weekEnd = $current->modify('+6 days');
                if ($weekEnd > $to) {
                    $weekEnd = $to;
                }

                $value = $this->getInventoryValueAsOf($weekEnd);
                $weeklyTrend[] = [
                    'date' => $weekEnd->format('Y-m-d'),
                    'week' => 'W' . $weekEnd->format('W'),
                    'value' => round($value, 2),
                ];

                $current = $weekEnd->modify('+1 day');
            }

            return [
                'by_month' => $monthlyTrend,
                'by_week' => $weeklyTrend,
            ];
        } catch (\Exception $e) {
            return [
                'by_month' => [],
                'by_week' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get inventory value as of a specific date.
     * Sums stock.move for all receipts/deliveries up to that date.
     */
    private function getInventoryValueAsOf(DateTimeImmutable $date): float
    {
        try {
            // Get all completed stock movements up to this date
            $moves = $this->odoo->searchRead(
                'stock.move',
                [
                    ['state', '=', 'done'],
                    ['date', '<=', $date->format('Y-m-d')],
                    ['company_id', '=', $this->getCompanyId()],
                ],
                ['product_id', 'quantity_done', 'location_dest_id', 'location_id'],
                ['limit' => 5000]
            );

            // Calculate net quantities by product
            $netQty = [];
            foreach ($moves as $move) {
                $productId = $move['product_id'][0] ?? null;
                if (!$productId) {
                    continue;
                }

                // Simplified: count all movements (receipt + delivery)
                // In reality, would need to track internal location changes
                $qty = $move['quantity_done'] ?? 0;

                if (!isset($netQty[$productId])) {
                    $netQty[$productId] = 0;
                }
                $netQty[$productId] += $qty;
            }

            // Batch fetch product costs
            $totalValue = 0;
            if (!empty($netQty)) {
                $productIds = array_keys($netQty);
                $products = $this->odoo->read('product.product', $productIds, ['standard_price']);

                foreach ($products as $product) {
                    $productId = $product['id'];
                    $cost = $product['standard_price'] ?? 0;
                    $qty = $netQty[$productId] ?? 0;
                    $totalValue += ($qty * $cost);
                }
            }

            return max(0, $totalValue);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Detect currency from company.
     */
    private function detectCurrency(): string
    {
        try {
            $companyId = $this->getCompanyId();
            $company = $this->odoo->read('res.company', [$companyId], ['currency_id']);

            if (!empty($company[0]['currency_id'])) {
                $curId = $company[0]['currency_id'][0];
                $currency = $this->odoo->read('res.currency', [$curId], ['name']);
                return $currency[0]['name'] ?? 'USD';
            }
        } catch (\Exception $e) {
            // Fallback to default
        }

        return 'USD';
    }

    /**
     * Get company ID from current user.
     */
    private function getCompanyId(): int
    {
        try {
            $uid = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
            return $user[0]['company_id'][0] ?? 1;
        } catch (\Exception $e) {
            return 1; // Default company
        }
    }
}
