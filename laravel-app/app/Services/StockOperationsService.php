<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * Stock Operations — the operational (warehouse-level) complement to
 * InventoryService's GL-level view.
 *
 * InventoryService answers "what is stock worth in the books?" from the
 * trial balance (3X accounts). This service answers the questions the
 * books can't: WHICH products, HOW MUCH on hand / reserved / incoming,
 * HOW OLD the stock is, what's slow-moving (drives the OHADA 39X
 * impairment provision), how disciplined counting/scrapping is, and
 * whether reorder rules cover the catalogue.
 *
 * Data sources (all standard Odoo Community models):
 *   stock.location              internal locations (also the "is the
 *                               Inventory module in use?" probe)
 *   stock.quant                 on-hand + reserved qty per product
 *   stock.move                  incoming pipeline, last-movement ageing,
 *                               cycle-count adjustments (is_inventory)
 *   stock.scrap                 authorized write-offs (shrinkage)
 *   stock.warehouse.orderpoint  min/max reordering rules
 *   stock.lot                   lots + expiry (stock.production.lot ≤ v15;
 *                               expiry field life_date ≤ v14)
 *   stock.valuation.layer       exact book valuation when automated
 *                               valuation is on (Community ships it via
 *                               stock_account; empty under manual
 *                               valuation → quant × standard_price
 *                               fallback)
 *   product.product / category  cost, category, storable flag, costing
 *                               method (is_storable on Odoo 18+,
 *                               type='product' before)
 *
 * Scoping: every query is scoped to the connection user's company —
 * ['company_id','=',cid] on company-required models (moves, quants,
 * scraps, move lines), ['company_id','in',[cid,false]] on models where
 * the field is optional/shared (locations, lots, orderpoints, products).
 * Without this, a multi-company database would mix one company's
 * on-hand with fleet-wide movement dates and quietly distort ageing.
 *
 * Resilience contract (mirrors CashFlow16WService):
 *   - If the module probe fails, return ['installed' => false] — the view
 *     shows a friendly "not in use" panel, NOT zeros (no outage-as-zero).
 *   - Every section runs inside safe(); a failing section contributes a
 *     SANITIZED entry to 'errors' (full exception goes to the log via
 *     ea_safe_error) and an empty value, never kills the page.
 *   - If movement history can't be read, ageing / slow movers / the
 *     provision are suppressed (null) rather than rendering every SKU
 *     as "never moved" with a worst-case provision.
 *   - Capped fetches emit a truncation warning instead of silently
 *     reporting partial totals as complete.
 */
final class StockOperationsService
{
    /** Hard caps — safety stops for very large installs. */
    private const QUANT_CAP    = 10000;
    private const LOCATION_CAP = 500;
    private const MOVE_CAP     = 5000;
    private const RULE_CAP     = 2000;
    private const CHUNK        = 500;

    /**
     * Indicative impairment tiers by ageing bucket (fraction of value).
     * These are STARTING POINTS for the 39X provision conversation, not
     * accounting policy — the view labels them "indicative".
     */
    private const PROVISION_TIERS = [
        'm181_365' => 0.25,
        'over365'  => 0.50,
    ];

    private ?int $companyId = null;
    private ?string $companyError = null;
    /** null = could not resolve (use dot-path filters); [] = genuinely none */
    private ?array $internalLocationIds = null;
    private bool $internalLocationsResolved = false;
    /** @var string[] non-fatal data-quality warnings (truncations etc.) */
    private array $warnings = [];

    public function __construct(private OdooClient $odoo) {}

    /**
     * @return array<string,mixed> see sections below; 'installed' => false
     *                             means the Inventory module isn't in use.
     */
    public function analyze(?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $errors = [];

        // ── Module probe ─────────────────────────────────────────────
        // stock.location ships with the Inventory module; if we can't
        // even count internal locations the module isn't installed (or
        // the connection is down — the caller distinguishes via its own
        // outer try/catch on connection errors). Deliberately unscoped:
        // this asks "does the model exist?", not "what can I see?".
        try {
            $internalCount = $this->odoo->searchCount('stock.location', [['usage', '=', 'internal']]);
        } catch (\Throwable $e) {
            return [
                'installed' => false,
                'as_of'     => $today->format('Y-m-d'),
                'errors'    => [],
            ];
        }

        $currency = $this->safe(fn () => $this->detectCurrency(), $errors, 'currency') ?? 'XAF';

        // ── Core per-SKU dataset ─────────────────────────────────────
        $quantRows = $this->safe(fn () => $this->fetchQuants(), $errors, 'stock on hand') ?? [];
        $productIds = array_keys($quantRows);

        $products = $this->safe(fn () => $this->fetchProducts($productIds), $errors, 'product master data') ?? [];
        $svl      = $this->safe(fn () => $this->fetchValuationLayers($productIds), $errors, 'valuation layers');
        $incoming = $this->safe(fn () => $this->fetchIncoming(), $errors, 'incoming receipts') ?? [];
        $sold90   = $this->safe(fn () => $this->fetchSold90d($productIds, $today), $errors, 'sales (90d)') ?? [];

        // Movement history drives ageing/slow-movers/provision. A FAILED
        // fetch (null) is fundamentally different from "no movements":
        // rendering failure as "everything never moved" would put a 50%
        // provision on the whole stock. When unavailable we suppress the
        // ageing analytics entirely.
        $lastMoveRaw = $this->safe(fn () => $this->fetchLastMoveDates($productIds), $errors, 'movement history');
        $ageingKnown = $lastMoveRaw !== null;
        $lastMove    = $lastMoveRaw ?? [];

        $skus = $this->buildSkus($quantRows, $products, $svl ?? [], $incoming, $lastMove, $sold90, $today, $ageingKnown);

        // ── Derived analytics (suppressed when movement history failed) ─
        $ageing     = $ageingKnown ? $this->buildAgeing($skus) : null;
        $slowMovers = $ageingKnown
            ? array_values(array_filter($skus, fn ($s) =>
                $s['on_hand'] > 0 && ($s['age_days'] === null || $s['age_days'] > 90)))
            : [];

        // ── Governance sections ──────────────────────────────────────
        $storableCount = $this->safe(fn () => $this->storableProductCount(), $errors, 'storable product count') ?? 0;
        $cycleCounts   = $this->safe(fn () => $this->fetchCycleCounts($today), $errors, 'cycle counts');
        $shrinkage     = $this->safe(fn () => $this->fetchShrinkage($today, $products), $errors, 'scrap history');
        $reorder       = $this->safe(fn () => $this->fetchReorderRules($skus, $storableCount), $errors, 'reordering rules');
        $expiry        = $this->safe(fn () => $this->fetchExpiry($today), $errors, 'lots / expiry');
        $hygiene       = $this->safe(fn () => $this->buildHygiene($skus, $products), $errors, 'master data checks');
        $costing       = $this->safe(fn () => $this->fetchCostingMethods(), $errors, 'costing methods');

        // ── Totals ───────────────────────────────────────────────────
        $positive = array_filter($skus, fn ($s) => $s['on_hand'] > 0);
        $locIds   = $this->getInternalLocationIds();
        $totals = [
            'on_hand_value'        => round(array_sum(array_map(fn ($s) => max(0.0, $s['value']), $skus)), 2),
            'sku_count'            => count($positive),
            'negative_stock_count' => count(array_filter($skus, fn ($s) => $s['on_hand'] < 0)),
            'slow_mover_value'     => $ageingKnown
                ? round(array_sum(array_map(fn ($s) => max(0.0, $s['value']), $slowMovers)), 2)
                : null,
            'reserved_units'       => round(array_sum(array_column($skus, 'reserved')), 2),
            'incoming_units'       => round(array_sum(array_column($skus, 'incoming')), 2),
            'storable_products'    => $storableCount,
            'internal_locations'   => is_array($locIds) ? count($locIds) : $internalCount,
        ];

        return [
            'installed'        => true,
            'as_of'            => $today->format('Y-m-d'),
            'currency'         => $currency,
            'valuation_source' => ($svl !== null && $svl !== []) ? 'stock.valuation.layer' : 'stock.quant × standard_price',
            'skus'             => $skus,
            'totals'           => $totals,
            'ageing'           => $ageing,
            'slow_movers'      => $slowMovers,
            'cycle_counts'     => $cycleCounts,
            'shrinkage'        => $shrinkage,
            'reorder'          => $reorder,
            'expiry'           => $expiry,
            'hygiene'          => $hygiene,
            'costing'          => $costing,
            'errors'           => array_merge($errors, $this->warnings),
        ];
    }

    /* ── Section fetchers ──────────────────────────────────────────── */

    /**
     * On-hand + reserved per product across internal locations.
     * Primary: read_group (no row cap — server-side aggregation).
     * Fallback: plain row fetch with a truncation warning at the cap.
     *
     * Location strategy: explicit id list when resolvable; dot-path
     * usage filter when the list failed or hit the cap (NEVER unfiltered
     * — quants in virtual/adjustment/scrap locations would contaminate
     * on-hand with phantom negative stock); empty result when the
     * database genuinely has no internal locations.
     *
     * @return array<int, array{on_hand: float, reserved: float}>
     */
    private function fetchQuants(): array
    {
        $locIds = $this->getInternalLocationIds();
        if ($locIds === []) return [];
        $locClause = $locIds === null
            ? ['location_id.usage', '=', 'internal']
            : ['location_id', 'in', $locIds];
        $domain = [['company_id', '=', $this->getCompanyId()], $locClause];

        try {
            $rows = $this->odoo->callModel('stock.quant', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['quantity:sum', 'reserved_quantity:sum'],
                'groupby' => ['product_id'],
                'lazy'    => false,
            ]);
            $byProduct = [];
            foreach ((array) $rows as $q) {
                $pid = $this->m2oId($q['product_id'] ?? null);
                if (!$pid) continue;
                $byProduct[$pid] = [
                    'on_hand'  => (float) ($q['quantity'] ?? $q['quantity:sum'] ?? 0),
                    'reserved' => (float) ($q['reserved_quantity'] ?? $q['reserved_quantity:sum'] ?? 0),
                ];
            }
            return $byProduct;
        } catch (\Throwable $e) {
            // read_group unsupported → plain fetch + client-side sum.
            $quants = $this->odoo->searchRead(
                'stock.quant',
                $domain,
                ['product_id', 'quantity', 'reserved_quantity'],
                ['limit' => self::QUANT_CAP]
            );
            if (count($quants) >= self::QUANT_CAP) {
                $this->warn('stock on hand: more than ' . self::QUANT_CAP . ' stock lines — totals are partial');
            }
            $byProduct = [];
            foreach ($quants as $q) {
                $pid = $this->m2oId($q['product_id'] ?? null);
                if (!$pid) continue;
                $byProduct[$pid] = [
                    'on_hand'  => ($byProduct[$pid]['on_hand']  ?? 0.0) + (float) ($q['quantity'] ?? 0),
                    'reserved' => ($byProduct[$pid]['reserved'] ?? 0.0) + (float) ($q['reserved_quantity'] ?? 0),
                ];
            }
            return $byProduct;
        }
    }

    /**
     * Batch product enrichment (read() returns archived products too,
     * which is exactly what we want for the archived-with-stock check).
     * @return array<int, array{name:string, code:string, category:string, cost:float, active:bool}>
     */
    private function fetchProducts(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
            $rows = $this->odoo->read('product.product', $chunk, ['name', 'default_code', 'standard_price', 'categ_id', 'active']);
            foreach ($rows as $r) {
                $out[(int) $r['id']] = [
                    'name'     => (string) ($r['name'] ?? ''),
                    'code'     => is_string($r['default_code'] ?? null) ? $r['default_code'] : '',
                    'category' => $this->m2oName($r['categ_id'] ?? null),
                    'cost'     => (float) ($r['standard_price'] ?? 0),
                    'active'   => (bool) ($r['active'] ?? true),
                ];
            }
        }
        return $out;
    }

    /**
     * Per-product book value from valuation layers, when the tenant runs
     * automated valuation. Returns [] when the model exists but has no
     * rows (manual valuation) and null bubble-up (exception) when the
     * model is missing entirely — both fall back to quant × cost.
     * @return array<int,float>
     */
    private function fetchValuationLayers(array $productIds): array
    {
        if (empty($productIds)) return [];
        $out = [];
        foreach (array_chunk(array_values($productIds), self::CHUNK) as $chunk) {
            $rows = $this->odoo->callModel('stock.valuation.layer', 'read_group', [
                'domain'  => [
                    ['product_id', 'in', $chunk],
                    ['company_id', '=', $this->getCompanyId()],
                ],
                'fields'  => ['value:sum'],
                'groupby' => ['product_id'],
                'lazy'    => false,
            ]);
            foreach ((array) $rows as $r) {
                $pid = $this->m2oId($r['product_id'] ?? null);
                if (!$pid) continue;
                $out[$pid] = (float) ($r['value'] ?? $r['value:sum'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Incoming pipeline: not-yet-done moves whose destination is an
     * internal location and whose source is not (i.e. receipts in
     * transit/awaiting).
     * @return array<int,float> product_id => qty
     */
    private function fetchIncoming(): array
    {
        $locIds = $this->getInternalLocationIds();
        if ($locIds === []) return [];
        $base = [
            ['state', 'not in', ['done', 'cancel', 'draft']],
            ['company_id', '=', $this->getCompanyId()],
        ];
        $domain = $locIds === null
            ? array_merge($base, [
                ['location_dest_id.usage', '=', 'internal'],
                ['location_id.usage', '!=', 'internal'],
            ])
            : array_merge($base, [
                ['location_dest_id', 'in', $locIds],
                ['location_id', 'not in', $locIds],
            ]);
        try {
            $rows = $this->odoo->callModel('stock.move', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['product_uom_qty:sum'],
                'groupby' => ['product_id'],
                'lazy'    => false,
            ]);
        } catch (\Throwable $e) {
            // read_group unsupported → plain fetch + client-side sum
            $moves = $this->odoo->searchRead('stock.move', $domain, ['product_id', 'product_uom_qty'], ['limit' => self::MOVE_CAP]);
            if (count($moves) >= self::MOVE_CAP) {
                $this->warn('incoming receipts: more than ' . self::MOVE_CAP . ' pending moves — incoming totals are partial');
            }
            $rows = [];
            $tmp  = [];
            foreach ($moves as $m) {
                $pid = $this->m2oId($m['product_id'] ?? null);
                if (!$pid) continue;
                $tmp[$pid] = ($tmp[$pid] ?? 0.0) + (float) ($m['product_uom_qty'] ?? 0);
            }
            foreach ($tmp as $pid => $qty) {
                $rows[] = ['product_id' => [$pid, ''], 'product_uom_qty' => $qty];
            }
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $pid = $this->m2oId($r['product_id'] ?? null);
            if (!$pid) continue;
            $out[$pid] = (float) ($r['product_uom_qty'] ?? $r['product_uom_qty:sum'] ?? 0);
        }
        return $out;
    }

    /**
     * Most recent DONE stock movement per product — the ageing anchor.
     * Per-chunk read_group with an ordered-scan fallback, so one
     * unsupported aggregate doesn't wipe the whole ageing dataset (and
     * silently flag everything "never moved").
     * @return array<int,string> product_id => 'YYYY-MM-DD...'
     */
    private function fetchLastMoveDates(array $productIds): array
    {
        if (empty($productIds)) return [];
        $out = [];
        foreach (array_chunk(array_values($productIds), self::CHUNK) as $chunk) {
            $domain = [
                ['state', '=', 'done'],
                ['product_id', 'in', $chunk],
                ['company_id', '=', $this->getCompanyId()],
            ];
            try {
                $rows = $this->odoo->callModel('stock.move', 'read_group', [
                    'domain'  => $domain,
                    'fields'  => ['date:max'],
                    'groupby' => ['product_id'],
                    'lazy'    => false,
                ]);
                foreach ((array) $rows as $r) {
                    $pid = $this->m2oId($r['product_id'] ?? null);
                    if (!$pid) continue;
                    $date = $r['date'] ?? $r['date:max'] ?? null;
                    if (is_string($date) && $date !== '') $out[$pid] = $date;
                }
            } catch (\Throwable $e) {
                // Fallback: newest-first scan, first hit per product wins.
                $moves = $this->odoo->searchRead(
                    'stock.move',
                    $domain,
                    ['product_id', 'date'],
                    ['limit' => self::MOVE_CAP, 'order' => 'date desc']
                );
                if (count($moves) >= self::MOVE_CAP) {
                    $this->warn('movement history: scan truncated at ' . self::MOVE_CAP . ' moves — ageing may overstate some products');
                }
                foreach ($moves as $m) {
                    $pid = $this->m2oId($m['product_id'] ?? null);
                    if (!$pid || isset($out[$pid])) continue;
                    $date = $m['date'] ?? null;
                    if (is_string($date) && $date !== '') $out[$pid] = $date;
                }
            }
        }
        return $out;
    }

    /**
     * Units invoiced per product over the trailing 90 days — sell-through
     * for days-of-cover. Invoice-based (account.move.line) so it works
     * even when deliveries aren't logged in the warehouse.
     * @return array<int,float>
     */
    private function fetchSold90d(array $productIds, DateTimeImmutable $today): array
    {
        if (empty($productIds)) return [];
        $from = $today->modify('-90 days')->format('Y-m-d');
        $out  = [];
        foreach (array_chunk(array_values($productIds), self::CHUNK) as $chunk) {
            $domain = [
                ['parent_state', '=', 'posted'],
                ['move_id.move_type', '=', 'out_invoice'],
                ['product_id', 'in', $chunk],
                ['date', '>=', $from],
                ['company_id', '=', $this->getCompanyId()],
            ];
            try {
                $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                    'domain'  => $domain,
                    'fields'  => ['quantity:sum'],
                    'groupby' => ['product_id'],
                    'lazy'    => false,
                ]);
                foreach ((array) $rows as $r) {
                    $pid = $this->m2oId($r['product_id'] ?? null);
                    if (!$pid) continue;
                    $out[$pid] = (float) ($r['quantity'] ?? $r['quantity:sum'] ?? 0);
                }
            } catch (\Throwable $e) {
                // read_group unsupported → plain fetch + client-side sum
                $lines = $this->odoo->searchRead('account.move.line', $domain, ['product_id', 'quantity'], ['limit' => self::MOVE_CAP]);
                if (count($lines) >= self::MOVE_CAP) {
                    $this->warn('sales (90d): more than ' . self::MOVE_CAP . ' invoice lines — sell-through is partial');
                }
                foreach ($lines as $l) {
                    $pid = $this->m2oId($l['product_id'] ?? null);
                    if (!$pid) continue;
                    $out[$pid] = ($out[$pid] ?? 0.0) + (float) ($l['quantity'] ?? 0);
                }
            }
        }
        return $out;
    }

    /** Assemble the per-SKU rows, sorted by absolute value desc. */
    private function buildSkus(
        array $quantRows,
        array $products,
        array $svl,
        array $incoming,
        array $lastMove,
        array $sold90,
        DateTimeImmutable $today,
        bool $ageingKnown,
    ): array {
        $skus = [];
        foreach ($quantRows as $pid => $q) {
            $p     = $products[$pid] ?? ['name' => "Product #{$pid}", 'code' => '', 'category' => '', 'cost' => 0.0, 'active' => true];
            $cost  = $p['cost'];
            $value = array_key_exists($pid, $svl) ? $svl[$pid] : $q['on_hand'] * $cost;

            $last    = $lastMove[$pid] ?? null;
            $ageDays = null;
            if ($last !== null) {
                try {
                    $diff = (new DateTimeImmutable(substr($last, 0, 10)))->diff($today);
                    // %a is UNSIGNED — a forward-dated move would read as
                    // hundreds of days old. invert=1 means the move date is
                    // in the future → the stock just moved → age 0.
                    $ageDays = $diff->invert === 1 ? 0 : (int) $diff->format('%a');
                } catch (\Throwable $e) {
                    $ageDays = null;
                }
            }

            $sold = (float) ($sold90[$pid] ?? 0);
            $skus[] = [
                'product_id' => $pid,
                'name'       => $p['name'],
                'code'       => $p['code'],
                'category'   => $p['category'],
                'active'     => $p['active'],
                'on_hand'    => round($q['on_hand'], 2),
                'reserved'   => round($q['reserved'], 2),
                'incoming'   => round((float) ($incoming[$pid] ?? 0), 2),
                'cost'       => round($cost, 2),
                'value'      => round($value, 2),
                'last_move'  => $last !== null ? substr($last, 0, 10) : null,
                'age_days'   => $ageDays,
                'bucket'     => $ageingKnown ? $this->bucketFor($ageDays) : 'unknown',
                'sold_90d'   => round($sold, 2),
                'days_cover' => $sold > 0 && $q['on_hand'] > 0 ? (int) round($q['on_hand'] / ($sold / 90)) : null,
            ];
        }
        usort($skus, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));
        return $skus;
    }

    private function bucketFor(?int $ageDays): string
    {
        return match (true) {
            $ageDays === null  => 'never',
            $ageDays <= 90     => 'd0_90',
            $ageDays <= 180    => 'd91_180',
            $ageDays <= 365    => 'm181_365',
            default            => 'over365',
        };
    }

    /**
     * Value by ageing bucket + the indicative 39X provision suggestion.
     * Only positive on-hand value counts toward a provision.
     */
    private function buildAgeing(array $skus): array
    {
        $buckets = [
            'd0_90'    => ['label' => 'Moved ≤ 90 days',    'value' => 0.0, 'sku_count' => 0, 'tier' => 0.0],
            'd91_180'  => ['label' => '91–180 days',        'value' => 0.0, 'sku_count' => 0, 'tier' => 0.0],
            'm181_365' => ['label' => '181–365 days',       'value' => 0.0, 'sku_count' => 0, 'tier' => self::PROVISION_TIERS['m181_365']],
            'over365'  => ['label' => 'No movement > 1 yr', 'value' => 0.0, 'sku_count' => 0, 'tier' => self::PROVISION_TIERS['over365']],
            'never'    => ['label' => 'Never moved',        'value' => 0.0, 'sku_count' => 0, 'tier' => self::PROVISION_TIERS['over365']],
        ];
        foreach ($skus as $s) {
            if ($s['on_hand'] <= 0) continue;
            $b = $s['bucket'];
            if (!isset($buckets[$b])) continue;
            $buckets[$b]['value']     += max(0.0, $s['value']);
            $buckets[$b]['sku_count'] += 1;
        }
        $provision = 0.0;
        foreach ($buckets as &$b) {
            $b['value']     = round($b['value'], 2);
            $b['provision'] = round($b['value'] * $b['tier'], 2);
            $provision     += $b['provision'];
        }
        unset($b);
        return [
            'buckets'              => $buckets,
            'indicative_provision' => round($provision, 2),
        ];
    }

    /**
     * Storable product count with the Odoo 18 rename handled:
     * is_storable (18+) → type='product' (≤17). Products may be shared
     * across companies (company_id = false), so the filter includes both.
     */
    private function storableProductCount(): int
    {
        $companyClause = ['company_id', 'in', [$this->getCompanyId(), false]];
        try {
            return $this->odoo->searchCount('product.product', [['active', '=', true], ['is_storable', '=', true], $companyClause]);
        } catch (\Throwable $e) {
            return $this->odoo->searchCount('product.product', [['active', '=', true], ['type', '=', 'product'], $companyClause]);
        }
    }

    /**
     * Cycle-count discipline over the trailing 12 months.
     * Odoo 15+: inventory adjustments are stock.move rows flagged
     * is_inventory. Older installs: stock.inventory documents (date-
     * filtered so the figure is genuinely 12-month, not all-time).
     */
    private function fetchCycleCounts(DateTimeImmutable $today): array
    {
        $from = $today->modify('-12 months')->format('Y-m-d');
        try {
            $domain = [
                ['is_inventory', '=', true],
                ['state', '=', 'done'],
                ['date', '>=', $from],
                ['company_id', '=', $this->getCompanyId()],
            ];
            $count  = $this->odoo->searchCount('stock.move', $domain);
            $lastRow = $this->odoo->searchRead('stock.move', $domain, ['date'], ['limit' => 1, 'order' => 'date desc']);
            $moves = $count > 0
                ? $this->odoo->searchRead('stock.move', $domain, ['product_id', 'product_uom_qty'], ['limit' => self::RULE_CAP])
                : [];
            if (count($moves) >= self::RULE_CAP) {
                $this->warn('cycle counts: adjustment detail truncated at ' . self::RULE_CAP . ' moves');
            }
            $skus = [];
            $qty  = 0.0;
            foreach ($moves as $m) {
                $pid = $this->m2oId($m['product_id'] ?? null);
                if ($pid) $skus[$pid] = true;
                $qty += abs((float) ($m['product_uom_qty'] ?? 0));
            }
            return [
                'available'        => true,
                'adjustments_12m'  => $count,
                'last_count_date'  => isset($lastRow[0]['date']) ? substr((string) $lastRow[0]['date'], 0, 10) : null,
                'skus_adjusted'    => count($skus),
                'qty_adjusted_12m' => round($qty, 2),
            ];
        } catch (\Throwable $e) {
            // Pre-v15 fallback: stock.inventory documents, date-filtered
            // so the 12-month label stays truthful.
            try {
                $domain = [
                    ['state', '=', 'done'],
                    ['date', '>=', $from],
                    ['company_id', '=', $this->getCompanyId()],
                ];
                $count   = $this->odoo->searchCount('stock.inventory', $domain);
                $lastRow = $this->odoo->searchRead('stock.inventory', $domain, ['date'], ['limit' => 1, 'order' => 'date desc']);
                return [
                    'available'        => true,
                    'adjustments_12m'  => $count,
                    'last_count_date'  => isset($lastRow[0]['date']) ? substr((string) $lastRow[0]['date'], 0, 10) : null,
                    'skus_adjusted'    => null,
                    'qty_adjusted_12m' => null,
                ];
            } catch (\Throwable $e2) {
                return ['available' => false];
            }
        }
    }

    /**
     * Shrinkage: validated scraps over the trailing 12 months, valued at
     * standard cost. date_done (v13+) → date_expected (older) → count-only
     * (explicitly labelled all-time so the view doesn't lie about it).
     */
    private function fetchShrinkage(DateTimeImmutable $today, array $products): array
    {
        $from = $today->modify('-12 months')->format('Y-m-d');
        $companyClause = ['company_id', '=', $this->getCompanyId()];
        $rows = null;
        $dateField = null;
        foreach (['date_done', 'date_expected'] as $field) {
            try {
                $rows = $this->odoo->searchRead(
                    'stock.scrap',
                    [['state', '=', 'done'], [$field, '>=', $from], $companyClause],
                    ['product_id', 'scrap_qty', $field],
                    ['limit' => self::RULE_CAP, 'order' => "{$field} desc"]
                );
                $dateField = $field;
                break;
            } catch (\Throwable $e) {
                // field doesn't exist on this version — try the next one
            }
        }
        if ($rows === null) {
            // No usable date field — degrade to an ALL-TIME count and say so.
            $count = $this->odoo->searchCount('stock.scrap', [['state', '=', 'done'], $companyClause]);
            return ['available' => true, 'count_12m' => null, 'count_all_time' => $count, 'qty_12m' => null, 'value_12m' => null, 'recent' => []];
        }
        if (count($rows) >= self::RULE_CAP) {
            $this->warn('scrap history: truncated at ' . self::RULE_CAP . ' records — shrinkage totals are partial');
        }

        // Value at cost — read costs for products not already enriched.
        $missing = [];
        foreach ($rows as $r) {
            $pid = $this->m2oId($r['product_id'] ?? null);
            if ($pid && !isset($products[$pid])) $missing[$pid] = true;
        }
        $extraCosts = [];
        foreach (array_chunk(array_keys($missing), self::CHUNK) as $chunk) {
            foreach ($this->odoo->read('product.product', $chunk, ['standard_price']) as $p) {
                $extraCosts[(int) $p['id']] = (float) ($p['standard_price'] ?? 0);
            }
        }

        $qty = 0.0; $value = 0.0; $recent = [];
        foreach ($rows as $r) {
            $pid  = $this->m2oId($r['product_id'] ?? null);
            $sQty = (float) ($r['scrap_qty'] ?? 0);
            $cost = $products[$pid]['cost'] ?? $extraCosts[$pid] ?? 0.0;
            $qty   += $sQty;
            $value += $sQty * $cost;
            if (count($recent) < 5) {
                $recent[] = [
                    'product' => $this->m2oName($r['product_id'] ?? null),
                    'qty'     => round($sQty, 2),
                    'value'   => round($sQty * $cost, 2),
                    'date'    => substr((string) ($r[$dateField] ?? ''), 0, 10),
                ];
            }
        }
        return [
            'available' => true,
            'count_12m' => count($rows),
            'qty_12m'   => round($qty, 2),
            'value_12m' => round($value, 2),
            'recent'    => $recent,
        ];
    }

    /**
     * Reorder-rule coverage + SKUs currently below their minimum.
     * Orderpoints can be company-specific; scope to ours (or shared).
     */
    private function fetchReorderRules(array $skus, int $storableCount): array
    {
        $rules = $this->odoo->searchRead(
            'stock.warehouse.orderpoint',
            [['company_id', 'in', [$this->getCompanyId(), false]]],
            ['product_id', 'product_min_qty', 'product_max_qty'],
            ['limit' => self::RULE_CAP]
        );
        if (count($rules) >= self::RULE_CAP) {
            $this->warn('reordering rules: truncated at ' . self::RULE_CAP . ' rules — coverage is partial');
        }
        $onHand = [];
        $names  = [];
        foreach ($skus as $s) {
            $onHand[$s['product_id']] = $s['on_hand'];
            $names[$s['product_id']]  = $s['name'];
        }
        $covered  = [];
        $belowMin = [];
        foreach ($rules as $r) {
            $pid = $this->m2oId($r['product_id'] ?? null);
            if (!$pid) continue;
            $covered[$pid] = true;
            $min = (float) ($r['product_min_qty'] ?? 0);
            $oh  = (float) ($onHand[$pid] ?? 0);
            if ($min > 0 && $oh < $min) {
                $belowMin[] = [
                    'product' => $names[$pid] ?? $this->m2oName($r['product_id'] ?? null),
                    'on_hand' => round($oh, 2),
                    'min'     => round($min, 2),
                    'max'     => round((float) ($r['product_max_qty'] ?? 0), 2),
                ];
            }
        }
        usort($belowMin, fn ($a, $b) => ($a['on_hand'] / max(0.001, $a['min'])) <=> ($b['on_hand'] / max(0.001, $b['min'])));
        return [
            'available'        => true,
            'rules_count'      => count($rules),
            'products_covered' => count($covered),
            'coverage_pct'     => $storableCount > 0 ? round(count($covered) / $storableCount * 100, 1) : null,
            'below_min'        => array_slice($belowMin, 0, 15),
        ];
    }

    /**
     * Lot/expiry visibility. stock.lot on Odoo 16+, stock.production.lot
     * before; the expiry field is expiration_date (15+) or life_date (≤14).
     */
    private function fetchExpiry(DateTimeImmutable $today): array
    {
        $companyClause = ['company_id', 'in', [$this->getCompanyId(), false]];
        $model = 'stock.lot';
        try {
            $lotCount = $this->odoo->searchCount($model, [$companyClause]);
        } catch (\Throwable $e) {
            $model = 'stock.production.lot';
            $lotCount = $this->odoo->searchCount($model, [$companyClause]); // throws to safe() if neither exists
        }
        if ($lotCount === 0) {
            return ['tracked' => false, 'lot_count' => 0];
        }
        $horizon = $today->modify('+90 days')->format('Y-m-d');
        $todayS  = $today->format('Y-m-d');
        foreach (['expiration_date', 'life_date'] as $expiryField) {
            try {
                $expiringRows = $this->odoo->searchRead(
                    $model,
                    [[$expiryField, '!=', false], [$expiryField, '>=', $todayS], [$expiryField, '<=', $horizon], $companyClause],
                    ['name', 'product_id', $expiryField],
                    ['limit' => 15, 'order' => "{$expiryField} asc"]
                );
                $expiredCount = $this->odoo->searchCount($model, [[$expiryField, '!=', false], [$expiryField, '<', $todayS], $companyClause]);
                return [
                    'tracked'       => true,
                    'lot_count'     => $lotCount,
                    'expiry_dates'  => true,
                    'expired_count' => $expiredCount,
                    'expiring_90d'  => array_map(fn ($r) => [
                        'lot'     => (string) ($r['name'] ?? ''),
                        'product' => $this->m2oName($r['product_id'] ?? null),
                        'date'    => substr((string) ($r[$expiryField] ?? ''), 0, 10),
                    ], $expiringRows),
                ];
            } catch (\Throwable $e) {
                // field missing on this version — try the older name
            }
        }
        // Lots exist but no expiry field — expiry tracking is off.
        return ['tracked' => true, 'lot_count' => $lotCount, 'expiry_dates' => false];
    }

    /**
     * Master-data hygiene flags that quietly distort every cost-based KPI.
     */
    private function buildHygiene(array $skus, array $products): array
    {
        $companyClause = ['company_id', 'in', [$this->getCompanyId(), false]];
        try {
            $zeroCost = $this->odoo->searchCount('product.product', [['active', '=', true], ['is_storable', '=', true], ['standard_price', '=', 0], $companyClause]);
        } catch (\Throwable $e) {
            try {
                $zeroCost = $this->odoo->searchCount('product.product', [['active', '=', true], ['type', '=', 'product'], ['standard_price', '=', 0], $companyClause]);
            } catch (\Throwable $e2) {
                $zeroCost = null;
            }
        }
        $archivedWithStock = 0;
        $negative = [];
        foreach ($skus as $s) {
            if (!$s['active'] && $s['on_hand'] > 0) $archivedWithStock++;
            if ($s['on_hand'] < 0) {
                $negative[] = ['product' => $s['name'], 'on_hand' => $s['on_hand']];
            }
        }
        return [
            'zero_cost_storables' => $zeroCost,
            'archived_with_stock' => $archivedWithStock,
            'negative_stock'      => array_slice($negative, 0, 10),
        ];
    }

    /**
     * Costing-method spread across product categories — the basis of the
     * written costing policy (standard vs AVCO vs FIFO).
     */
    private function fetchCostingMethods(): array
    {
        $rows = $this->odoo->searchRead(
            'product.category',
            [],
            ['name', 'property_cost_method', 'property_valuation'],
            ['limit' => 200]
        );
        $methods = [];
        foreach ($rows as $r) {
            $m = (string) ($r['property_cost_method'] ?: 'standard');
            $methods[$m] = ($methods[$m] ?? 0) + 1;
        }
        $automated = count(array_filter($rows, fn ($r) => ($r['property_valuation'] ?? '') === 'real_time'));
        return [
            'available'            => true,
            'category_count'       => count($rows),
            'methods'              => $methods,
            'automated_valuation'  => $automated,
        ];
    }

    /* ── Plumbing ──────────────────────────────────────────────────── */

    /**
     * Resolve the scoping company. NEVER silently defaults — a wrong
     * company id would scope quants/valuation to another company's stock
     * with no signal. Resolution order: connection user's company →
     * first company the API key can read → throw (lands in the calling
     * section's safe(), so the failure is visible in errors[]).
     */
    private function getCompanyId(): int
    {
        if ($this->companyId !== null) return $this->companyId;
        if ($this->companyError !== null) throw new \RuntimeException($this->companyError);

        try {
            $uid  = $this->odoo->authenticate();
            $rows = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid  = $this->m2oId($rows[0]['company_id'] ?? null);
            if ($cid) return $this->companyId = $cid;
        } catch (\Throwable $e) {
            // fall through to the company probe
        }
        try {
            $rows = $this->odoo->searchRead('res.company', [], ['id'], ['limit' => 1, 'order' => 'id asc']);
            $cid  = (int) ($rows[0]['id'] ?? 0);
            if ($cid) return $this->companyId = $cid;
        } catch (\Throwable $e) {
            // fall through
        }
        $this->companyError = 'could not resolve the Odoo company for scoping';
        throw new \RuntimeException($this->companyError);
    }

    /**
     * Internal location ids, company-scoped (locations can be shared →
     * company_id false included).
     *
     * Returns:
     *   array (non-empty) — resolved id list, safe for in/not-in domains
     *   []                — the database genuinely has no internal locations
     *   null              — could NOT resolve (RPC failure or the id list
     *                       hit LOCATION_CAP, where a truncated list would
     *                       corrupt in/not-in filters) → callers switch to
     *                       location_id.usage dot-path domains instead.
     */
    private function getInternalLocationIds(): ?array
    {
        if ($this->internalLocationsResolved) return $this->internalLocationIds;
        $this->internalLocationsResolved = true;
        try {
            $rows = $this->odoo->searchRead(
                'stock.location',
                [['usage', '=', 'internal'], ['company_id', 'in', [$this->getCompanyId(), false]]],
                ['id'],
                ['limit' => self::LOCATION_CAP]
            );
            $ids = array_map(fn ($r) => (int) $r['id'], $rows);
            return $this->internalLocationIds = (count($ids) >= self::LOCATION_CAP ? null : $ids);
        } catch (\Throwable $e) {
            return $this->internalLocationIds = null;
        }
    }

    private function detectCurrency(): string
    {
        $rows = $this->odoo->read('res.company', [$this->getCompanyId()], ['currency_id']);
        $cid  = $this->m2oId($rows[0]['currency_id'] ?? null);
        if (!$cid) return 'XAF';
        $cur = $this->odoo->read('res.currency', [$cid], ['name']);
        return (string) ($cur[0]['name'] ?? 'XAF');
    }

    private function m2oId(mixed $v): ?int
    {
        if (is_array($v) && isset($v[0])) return (int) $v[0];
        if (is_int($v)) return $v;
        return null;
    }

    private function m2oName(mixed $v): string
    {
        return is_array($v) && isset($v[1]) ? (string) $v[1] : '';
    }

    /** Queue a non-fatal data-quality warning (truncation etc.). */
    private function warn(string $msg): void
    {
        $this->warnings[] = $msg;
    }

    /**
     * Run a section; on failure log the FULL exception server-side and
     * record only a sanitized label in $errors (raw Odoo fault strings
     * embed hostnames/tracebacks and this array renders on the
     * tenant-facing page — and gets cached).
     */
    private function safe(callable $fn, array &$errors, string $section)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errors[] = ea_safe_error($e, "stock_ops_{$section}", "{$section}: unavailable");
            return null;
        }
    }
}
