<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Odoo\OdooClient;
use Illuminate\Support\Facades\DB;

/**
 * P2 — warehouse ingestion.
 *
 * Copies the Odoo facts the BMS reports on into local tables, so pages can
 * read indexed SQL instead of paying network round-trips per view.
 *
 * Incremental by default: each model carries a watermark (the highest
 * write_date seen), and a sync pulls only records changed since. A full
 * backfill is the same code path with the watermark ignored. Both are safe
 * to re-run — every row is upserted on (tenant_id, odoo_id), so nothing
 * duplicates and a half-finished run simply resumes.
 *
 * The watermark advances ONLY after a model's pages are all written, so an
 * interrupted sync re-reads that slice next time rather than skipping it.
 */
final class WarehouseSyncService
{
    /** Rows per Odoo page. */
    private const PAGE = 1000;

    /**
     * Wall-clock stop time (unix seconds, 0 = unlimited). A long backfill
     * driven over HTTP must stop before the gateway cuts the connection;
     * because the watermark advances per page, the next call resumes from
     * where this one stopped.
     */
    private float $deadline = 0.0;

    /** Rows per database upsert batch. */
    private const CHUNK = 500;

    /** Which of the tenant's data sources this run reads (see DataSource). */
    private DataSource $source;

    /** Added to every id written — 0 for the primary source. */
    private int $off = 0;

    public function __construct(
        private OdooClient $odoo,
        private Tenant $tenant,
        ?DataSource $source = null,
    ) {
        $this->source = $source ?? DataSource::primary();
        $this->off    = $this->source->idOffset();
    }

    /** Warehouse id for an Odoo record id. */
    private function id(mixed $raw): int
    {
        return (int) $raw + $this->off;
    }

    /** Warehouse id for an Odoo many2one value ([id, name] or false). */
    private function ref(mixed $m2o): ?int
    {
        return is_array($m2o) ? (int) $m2o[0] + $this->off : null;
    }

    /** Odoo record id for a warehouse id. */
    private function raw(mixed $id): int
    {
        return (int) $id - $this->off;
    }

    /**
     * Sync every model for this tenant.
     *
     * @return array<string, array{rows:int, seconds:float, error:?string}>
     */
    public function syncAll(bool $full = false, ?callable $progress = null, ?string $onlyModel = null, float $deadline = 0.0): array
    {
        $this->deadline = $deadline;
        $out = [];
        foreach ([
            // Dimensions first so facts resolve against a populated set.
            'res.partner'               => fn () => $this->syncPartners($full),
            'account.account'           => fn () => $this->syncAccounts($full),
            'account.journal'           => fn () => $this->syncJournals($full),
            'product.product'           => fn () => $this->syncProducts($full),
            'account.move'              => fn () => $this->syncInvoices($full),
            'account.move.line'         => fn () => $this->syncGlLines($full),
            'account.payment'           => fn () => $this->syncPayments($full),
            'account.partial.reconcile' => fn () => $this->syncReconciles($full),
        ] as $model => $fn) {
            if ($onlyModel !== null && $onlyModel !== $model) continue;
            $t0 = microtime(true);
            try {
                $rows = $fn();
                $out[$model] = ['rows' => $rows, 'seconds' => round(microtime(true) - $t0, 1), 'error' => null];
                $this->note($model, null);
            } catch (\Throwable $e) {
                $out[$model] = ['rows' => 0, 'seconds' => round(microtime(true) - $t0, 1), 'error' => $e->getMessage()];
                $this->note($model, $e->getMessage());
            }
            if ($progress) $progress($model, $out[$model]);
        }
        return $out;
    }

    /**
     * Field names available on a model, cached per run.
     *
     * Tenants run different Odoo versions and carry different custom
     * modules — Transatlantic has a Type_voyage field on account.move that
     * no other tenant has. Asking for a field the instance doesn't define
     * makes Odoo reject the ENTIRE read, so optional fields must be probed
     * before they are requested. Returns [] when the probe itself fails,
     * which makes hasField() answer false and the caller skip the field.
     *
     * @var array<string, list<string>>
     */
    private array $fieldCache = [];

    private function hasField(string $model, string $field): bool
    {
        if (!array_key_exists($model, $this->fieldCache)) {
            try {
                $f = (array) $this->odoo->callModel($model, 'fields_get', [
                    'allfields' => [], 'attributes' => ['type'],
                ]);
                $this->fieldCache[$model] = array_keys($f);
            } catch (\Throwable $e) {
                $this->fieldCache[$model] = [];
            }
        }
        return in_array($field, $this->fieldCache[$model], true);
    }

    // ── Dimensions ──────────────────────────────────────────────────────

    private function syncProducts(bool $full): int
    {
        return $this->pull('product.product', $full,
            ['name', 'default_code', 'categ_id', 'detailed_type', 'list_price', 'write_date'],
            fn (array $r) => [
                'tenant_id'        => $this->tenant->id,
                'odoo_id'          => $this->id($r['id']),
                'name'             => mb_substr((string) ($r['name'] ?: ''), 0, 255),
                'code'             => mb_substr((string) ($r['default_code'] ?: ''), 0, 64) ?: null,
                'category_name'    => is_array($r['categ_id'] ?? null)
                    ? mb_substr((string) ($r['categ_id'][1] ?? ''), 0, 191) : null,
                'category_odoo_id' => $this->ref($r['categ_id'] ?? null),
                'product_type'     => mb_substr((string) ($r['detailed_type'] ?: ''), 0, 32) ?: null,
                'list_price'       => (float) ($r['list_price'] ?? 0),
                'write_date'       => $this->dt($r['write_date'] ?? null),
            ],
            'wh_dim_product');
    }

    private function syncJournals(bool $full): int
    {
        return $this->pull('account.journal', $full,
            ['name', 'code', 'type', 'write_date'],
            function (array $r) {
                $name = (string) ($r['name'] ?: '');
                return [
                    'tenant_id'      => $this->tenant->id,
                    'odoo_id'        => $this->id($r['id']),
                    'name'           => mb_substr($name, 0, 191),
                    'code'           => mb_substr((string) ($r['code'] ?: ''), 0, 32) ?: null,
                    'journal_type'   => mb_substr((string) ($r['type'] ?: ''), 0, 32) ?: null,
                    // Withholding tax is booked in dedicated journals whose
                    // names carry "RETENUE A LA SOURCE" (per client). The
                    // flag is what makes a withholding report possible at all.
                    'is_withholding' => (bool) preg_match('/retenue\s*(a|à)\s*la\s*source|withhold/i', $name),
                    'write_date'     => $this->dt($r['write_date'] ?? null),
                ];
            },
            'wh_dim_journal');
    }

    private function syncPartners(bool $full): int
    {
        return $this->pull('res.partner', $full,
            ['name', 'ref', 'is_company', 'write_date'],
            fn (array $r) => [
                'tenant_id'  => $this->tenant->id,
                'odoo_id'    => $this->id($r['id']),
                'name'       => mb_substr((string) ($r['name'] ?: ''), 0, 255),
                'ref'        => mb_substr((string) ($r['ref'] ?: ''), 0, 64) ?: null,
                'is_company' => (bool) ($r['is_company'] ?? false),
                'write_date' => $this->dt($r['write_date'] ?? null),
            ],
            'wh_dim_partner');
    }

    private function syncAccounts(bool $full): int
    {
        return $this->pull('account.account', $full,
            ['code', 'name', 'write_date'],
            function (array $r) {
                $code = (string) ($r['code'] ?: '');
                return [
                    'tenant_id'     => $this->tenant->id,
                    'odoo_id'       => $this->id($r['id']),
                    'code'          => mb_substr($code, 0, 32),
                    'name'          => mb_substr((string) ($r['name'] ?: ''), 0, 255),
                    'account_class' => $code !== '' ? substr($code, 0, 1) : null,
                    'write_date'    => $this->dt($r['write_date'] ?? null),
                ];
            },
            'wh_dim_account');
    }

    // ── Facts ───────────────────────────────────────────────────────────

    private function syncInvoices(bool $full): int
    {
        // Type_voyage is Transatlantic's custom domestique/transit split.
        // Probed, never assumed: requesting it on an instance that lacks it
        // would make Odoo reject the whole read and break every tenant.
        $hasVoyage = $this->hasField('account.move', 'Type_voyage');

        $fields = ['name', 'move_type', 'state', 'payment_state', 'invoice_date', 'invoice_date_due',
                   'partner_id', 'journal_id', 'amount_untaxed_signed', 'amount_total_signed',
                   'amount_residual_signed', 'write_date'];
        if ($hasVoyage) $fields[] = 'Type_voyage';

        return $this->pull('account.move', $full, $fields,
            function (array $r) use ($hasVoyage) {
                $voyage = null;
                if ($hasVoyage) {
                    $v = $r['Type_voyage'] ?? null;
                    // Selection fields read back as false when unset.
                    $voyage = ($v === false || $v === null || $v === '')
                        ? null : mb_substr((string) $v, 0, 32);
                }
                return [
                    'tenant_id'        => $this->tenant->id,
                    'odoo_id'          => $this->id($r['id']),
                    'name'             => mb_substr((string) ($r['name'] ?: ''), 0, 64),
                    'move_type'        => (string) ($r['move_type'] ?: ''),
                    'state'            => (string) ($r['state'] ?: ''),
                    'payment_state'    => (string) ($r['payment_state'] ?: ''),
                    'voyage_type'      => $voyage,
                    'invoice_date'     => $this->d($r['invoice_date'] ?? null),
                    'invoice_date_due' => $this->d($r['invoice_date_due'] ?? null),
                    'partner_odoo_id'  => $this->ref($r['partner_id'] ?? null),
                    'journal_odoo_id'  => $this->ref($r['journal_id'] ?? null),
                    'amount_untaxed'   => (float) ($r['amount_untaxed_signed'] ?? 0),
                    'amount_total'     => (float) ($r['amount_total_signed'] ?? 0),
                    'amount_residual'  => (float) ($r['amount_residual_signed'] ?? 0),
                    'write_date'       => $this->dt($r['write_date'] ?? null),
                ];
            },
            'wh_fact_invoice',
            // Customer + supplier documents only; journal entries are not
            // invoice facts and would distort every count.
            [['move_type', 'in', ['out_invoice', 'out_refund', 'in_invoice', 'in_refund']]]);
    }

    /**
     * Customer/supplier payments. Separate from the GL because a payment is
     * a business event in its own right: collections, DSO and cash
     * application all key off it, and the GL line alone cannot say which
     * journal received the money or whether it was inbound or outbound.
     */
    private function syncPayments(bool $full): int
    {
        return $this->pull('account.payment', $full,
            ['name', 'ref', 'partner_id', 'journal_id', 'move_id', 'payment_type',
             'partner_type', 'state', 'date', 'amount', 'write_date'],
            fn (array $r) => [
                'tenant_id'       => $this->tenant->id,
                'odoo_id'         => $this->id($r['id']),
                'name'            => mb_substr((string) ($r['name'] ?: ''), 0, 64),
                'ref'             => mb_substr((string) ($r['ref'] ?: ''), 0, 128) ?: null,
                'partner_odoo_id' => $this->ref($r['partner_id'] ?? null),
                'journal_odoo_id' => $this->ref($r['journal_id'] ?? null),
                'move_odoo_id'    => $this->ref($r['move_id'] ?? null),
                'payment_type'    => mb_substr((string) ($r['payment_type'] ?: ''), 0, 16) ?: null,
                'partner_type'    => mb_substr((string) ($r['partner_type'] ?: ''), 0, 16) ?: null,
                'state'           => mb_substr((string) ($r['state'] ?: ''), 0, 32) ?: null,
                'payment_date'    => $this->d($r['date'] ?? null),
                'amount'          => (float) ($r['amount'] ?? 0),
                'write_date'      => $this->dt($r['write_date'] ?? null),
            ],
            'wh_fact_payment');
    }

    /**
     * Cash application — which credit cleared which debit, and for how much.
     * Stored at LINE level because that is how Odoo models it; the parent
     * moves are resolved afterwards from wh_fact_gl_line (see
     * resolveReconcileMoves), which avoids a second round-trip per row.
     */
    private function syncReconciles(bool $full): int
    {
        return $this->pull('account.partial.reconcile', $full,
            ['debit_move_id', 'credit_move_id', 'amount', 'max_date', 'write_date'],
            fn (array $r) => [
                'tenant_id'          => $this->tenant->id,
                'odoo_id'            => $this->id($r['id']),
                'debit_line_odoo_id' => $this->ref($r['debit_move_id'] ?? null),
                'credit_line_odoo_id'=> $this->ref($r['credit_move_id'] ?? null),
                'debit_move_odoo_id' => null,   // resolved post-pull
                'credit_move_odoo_id'=> null,
                'amount'             => (float) ($r['amount'] ?? 0),
                'max_date'           => $this->d($r['max_date'] ?? null),
                'write_date'         => $this->dt($r['write_date'] ?? null),
            ],
            'wh_fact_reconcile');
    }

    /**
     * Fill wh_fact_reconcile's parent-move columns from the GL lines already
     * in the warehouse. Set-based, idempotent, and only touches rows whose
     * move is still unknown — so it costs nothing once caught up.
     */
    public function resolveReconcileMoves(): int
    {
        $fixed = 0;
        foreach ([['debit_line_odoo_id', 'debit_move_odoo_id'],
                  ['credit_line_odoo_id', 'credit_move_odoo_id']] as [$lineCol, $moveCol]) {
            $fixed += ea_tdb()->table('wh_fact_reconcile as r')
                ->join('wh_fact_gl_line as l', function ($j) use ($lineCol) {
                    $j->on('l.tenant_id', '=', 'r.tenant_id')->on('l.odoo_id', '=', 'r.' . $lineCol);
                })
                ->where('r.tenant_id', $this->tenant->id)
                ->whereNull('r.' . $moveCol)
                ->whereNotNull('l.move_odoo_id')
                ->update(['r.' . $moveCol => DB::raw('l.move_odoo_id')]);
        }
        return $fixed;
    }

    /**
     * Every GL move line. There is deliberately no domain filter: this is
     * the general ledger, so journal entries, payments and openings belong
     * here alongside invoice lines. Invoice lines are the subset whose
     * move_odoo_id resolves in wh_fact_invoice.
     */
    private function syncGlLines(bool $full): int
    {
        return $this->pull('account.move.line', $full,
            ['move_id', 'account_id', 'product_id', 'journal_id', 'quantity', 'price_unit', 'discount',
             'price_subtotal', 'debit', 'credit', 'parent_state', 'date', 'write_date', 'display_type'],
            fn (array $r) => $this->glRow($r),
            'wh_fact_gl_line');
    }

    /**
     * One Odoo move line => one warehouse row. Shared by the incremental
     * sync and the column backfill so the two can never disagree about what
     * a GL row contains.
     */
    private function glRow(array $r): array
    {
        $mv = $r['move_id'] ?? null;
        return [
            'tenant_id'       => $this->tenant->id,
            'odoo_id'         => $this->id($r['id']),
            'move_odoo_id'    => $this->ref($mv),
            'account_odoo_id' => $this->ref($r['account_id'] ?? null),
            'product_odoo_id' => $this->ref($r['product_id'] ?? null),
            'journal_odoo_id' => $this->ref($r['journal_id'] ?? null),
            // Two dates, on purpose. gl_date is the accounting date as booked
            // and is never rewritten. invoice_date starts as the same value
            // but gets replaced by the parent document's date in
            // reconcileLineDates(), so revenue reports in the invoice's month
            // — which is not always the month the entry was posted in.
            'gl_date'         => $this->d($r['date'] ?? null),
            'invoice_date'    => $this->d($r['date'] ?? null),
            'move_type'       => (string) ($r['display_type'] ?: ''),
            'quantity'        => (float) ($r['quantity'] ?? 0),
            'price_unit'      => (float) ($r['price_unit'] ?? 0),
            'discount'        => (float) ($r['discount'] ?? 0),
            'price_subtotal'  => (float) ($r['price_subtotal'] ?? 0),
            'debit'           => (float) ($r['debit'] ?? 0),
            'credit'          => (float) ($r['credit'] ?? 0),
            'parent_state'    => (string) ($r['parent_state'] ?: ''),
            'write_date'      => $this->dt($r['write_date'] ?? null),
        ];
    }

    // ── Engine ──────────────────────────────────────────────────────────

    /**
     * Pull one model incrementally (or fully) and upsert it.
     *
     * @param list<string>            $fields  Odoo fields to read
     * @param callable(array):array   $map     Odoo record => DB row
     * @param list<array>             $extra   Additional domain conditions
     */
    private function pull(string $model, bool $full, array $fields, callable $map, string $table, array $extra = []): int
    {
        $state = $this->state($model);

        // Two separate jobs, and conflating them is what broke this twice.
        //
        // The WINDOW (write_date >= $windowFrom) decides which records a run
        // cares about. PAGINATION (id > $lastId) decides how it walks them.
        //
        // Progress must never come from write_date. Odoo reports write_date to
        // whole seconds while Postgres stores microseconds, so a boundary row
        // saved as '10:55:31' still satisfies write_date > '10:55:31' — its
        // real value is 10:55:31.123456 — and comes back on every query. The
        // previous cursor took its resume point from exactly that comparison
        // and locked solid; the offset paging before it only escaped because
        // the offset advanced independently of any timestamp.
        //
        // id is an integer and strictly ordered. It cannot express a boundary
        // it then fails to move past.
        $windowFrom = $full ? null : ($state->last_write_date ?? null);
        $lastId     = $full ? null : ($state->last_id ?? null);

        if ($full) {
            // Clear the stored window too, not just the local variable. A full
            // pass rarely finishes inside one run's budget, and if the old
            // window survived in the database the resuming run would re-apply
            // it and silently skip everything below it — turning a full
            // backfill into a partial one that still reports success.
            ea_tdb()->table('wh_sync_state')->where('id', $state->id)->update([
                'last_write_date' => null,
                'last_id'         => null,
                'updated_at'      => now(),
            ]);
        }

        $rows    = 0;
        $maxSeen = $windowFrom;
        $drained = false;

        while (true) {
            $domain = $extra;
            if ($windowFrom !== null) {
                // Inclusive, which is what makes sub-second precision harmless:
                // re-reading the boundary second costs nothing because every
                // row upserts.
                $domain[] = ['write_date', '>=', (string) $windowFrom];
            }
            if ($lastId !== null) {
                $domain[] = ['id', '>', (int) $lastId];
            }

            $page = $this->odoo->searchRead($model, $domain, array_merge(['id'], $fields), [
                'limit' => self::PAGE,
                // Must match the pagination key, not the window key.
                'order' => 'id asc',
            ]);
            if ($page === []) { $drained = true; break; }

            $batch = [];
            foreach ($page as $r) {
                $batch[] = $map($r);
                $w = $this->dt($r['write_date'] ?? null);
                if ($w !== null && ($maxSeen === null || $w > $maxSeen)) $maxSeen = $w;
            }
            foreach (array_chunk($batch, self::CHUNK) as $chunk) {
                ea_tdb()->table($table)->upsert(
                    $chunk,
                    ['tenant_id', 'odoo_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['tenant_id', 'odoo_id']))
                );
            }

            $lastId = (int) $page[count($page) - 1]['id'];
            $rows  += count($page);

            // Mid-window: keep the window open and record only how far the id
            // walk reached. Advancing the window here would strand whatever
            // this run has not got to yet.
            ea_tdb()->table('wh_sync_state')->where('id', $state->id)->update([
                'last_id'     => $lastId,
                'last_run_at' => now(),
                'rows_synced' => (int) $state->rows_synced + $rows,
                'updated_at'  => now(),
            ]);

            if (count($page) < self::PAGE) { $drained = true; break; }

            // Out of time — stop cleanly; last_id marks the resume point.
            if ($this->deadline > 0 && microtime(true) >= $this->deadline) break;
        }

        if ($drained) {
            // Window walked to the end: advance it and clear the id cursor so
            // the next run rescans the new window from the start. That rescan
            // is the point — a low-id record edited later would be invisible
            // to an id cursor that never resets.
            ea_tdb()->table('wh_sync_state')->where('id', $state->id)->update([
                'last_write_date' => $maxSeen,
                'last_id'         => null,
                'last_run_at'     => now(),
                'updated_at'      => now(),
            ]);
        }

        return $rows;
    }

    /**
     * Re-fetch GL lines whose row predates the accounting columns.
     *
     * Adding columns to a fact table leaves every existing row NULL in them,
     * and an incremental sync will never come back for those rows: their
     * write_date sits far behind the cursor. A --full pass would refresh
     * them, but it restarts from the beginning on every invocation, so it
     * can only ever finish if one invocation outlives the whole dataset —
     * which it cannot behind a gateway timeout.
     *
     * So select the stale rows by the thing that actually identifies them —
     * a NULL in the new columns — and fetch exactly those by id. Each pass
     * shrinks the set, which makes it resumable by construction and lets it
     * run in as many short bursts as the gateway allows.
     *
     * Rows whose Odoo record has since been deleted are removed rather than
     * left behind: they can never be filled, and leaving them would mean the
     * stale count never reaches zero and the backfill never reads as done.
     */
    public function backfillGlColumns(): array
    {
        $filled = 0; $removed = 0;

        while (true) {
            $ids = ea_tdb()->table('wh_fact_gl_line')
                ->where('tenant_id', $this->tenant->id)
                ->where(fn ($q) => $q->whereNull('gl_date')->orWhereNull('parent_state')->orWhere('parent_state', ''))
                ->whereBetween('odoo_id', $this->source->idRange())
                ->orderBy('odoo_id')
                ->limit(self::PAGE)
                ->pluck('odoo_id')
                ->all();
            if ($ids === []) break;
            $ids = array_map(fn ($i) => $this->raw($i), $ids);

            $recs = $this->odoo->searchRead('account.move.line', [['id', 'in', $ids]],
                ['id', 'move_id', 'account_id', 'product_id', 'journal_id', 'quantity', 'price_unit',
                 'discount', 'price_subtotal', 'debit', 'credit', 'parent_state', 'date', 'write_date',
                 'display_type'], []);

            $batch = []; $seen = [];
            foreach ($recs as $r) {
                $batch[] = $this->glRow($r);
                $seen[(int) $r['id']] = true;
            }
            foreach (array_chunk($batch, self::CHUNK) as $chunk) {
                ea_tdb()->table('wh_fact_gl_line')->upsert(
                    $chunk,
                    ['tenant_id', 'odoo_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['tenant_id', 'odoo_id']))
                );
            }
            $filled += count($batch);

            // Asked for these ids and Odoo did not return them — gone upstream.
            $gone = array_values(array_diff($ids, array_keys($seen)));
            if ($gone !== []) {
                ea_tdb()->table('wh_fact_gl_line')
                    ->where('tenant_id', $this->tenant->id)
                    ->whereIn('odoo_id', array_map(fn ($i) => $this->id($i), $gone))
                    ->delete();
                $removed += count($gone);
            }

            if ($this->deadline > 0 && microtime(true) >= $this->deadline) break;
        }

        $left = ea_tdb()->table('wh_fact_gl_line')
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('odoo_id', $this->source->idRange())
            ->where(fn ($q) => $q->whereNull('gl_date')->orWhereNull('parent_state')->orWhere('parent_state', ''))
            ->count();

        return ['filled' => $filled, 'removed' => $removed, 'remaining' => $left];
    }

    /** Set the wall-clock stop time for a backfill driven over HTTP. */
    public function setDeadline(float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * Re-check state and existence for GL lines in a date window.
     *
     * Two things an upsert-only sync can never learn, both measured on
     * 13 Aug 2026 (kwr April: 2 surplus posted lines worth 11,925;
     * transatlanticd July: 1 worth 50,000):
     *
     *  1. parent_state is derived from the parent move. Posting, resetting to
     *     draft or cancelling a move flips it on every line, but does not
     *     reliably bump the LINE's write_date — and the line watermark is what
     *     the sync resumes from. A move whose state changed after its lines
     *     were last read keeps the old state here permanently.
     *  2. Deleting a move deletes its lines. An upsert never removes anything,
     *     so those lines stay, still flagged posted, and keep contributing to
     *     every balance for ever.
     *
     * Both present the same way: the warehouse reports MORE posted rows than
     * Odoo, and no amount of ordinary syncing corrects it.
     *
     * Windowed because the id list is held in memory; a year at a time is the
     * intended granularity.
     */
    public function reconcileGlState(string $from, string $to): array
    {
        $state  = [];   // odoo id => current parent_state
        $lastId = 0;
        $complete = false;

        while (true) {
            $page = $this->odoo->searchRead('account.move.line',
                [['date', '>=', $from], ['date', '<=', $to], ['id', '>', $lastId]],
                ['id', 'parent_state'],
                ['limit' => 2000, 'order' => 'id asc']);
            if ($page === []) { $complete = true; break; }
            foreach ($page as $r) $state[(int) $r['id']] = (string) ($r['parent_state'] ?: '');
            $lastId = (int) $page[count($page) - 1]['id'];
            if (count($page) < 2000) { $complete = true; break; }
            if ($this->deadline > 0 && microtime(true) >= $this->deadline) break;
        }

        // Correct states that drifted. Grouped by target value so this is a
        // handful of UPDATEs rather than one per row.
        $fixed = 0;
        $byState = [];
        foreach ($state as $id => $st) $byState[$st][] = $id;
        foreach ($byState as $st => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $fixed += ea_tdb()->table('wh_fact_gl_line')
                    ->where('tenant_id', $this->tenant->id)
                    ->whereIn('odoo_id', array_map(fn ($i) => $this->id($i), $chunk))
                    ->where(function ($q) use ($st) {
                        $q->where('parent_state', '!=', $st)->orWhereNull('parent_state');
                    })
                    ->update(['parent_state' => $st]);
            }
        }

        // Remove what no longer exists upstream. Only safe on a COMPLETE scan:
        // a window cut short by the deadline has an incomplete id set, and
        // deleting against it would wipe rows that simply were not read yet.
        $removed = 0;
        if ($complete) {
            $whIds = ea_tdb()->table('wh_fact_gl_line')
                ->where('tenant_id', $this->tenant->id)
                ->whereBetween('odoo_id', $this->source->idRange())
                ->whereBetween('gl_date', [$from, $to])
                ->pluck('odoo_id')->map(fn ($i) => $this->raw($i))->all();
            $gone = array_values(array_diff($whIds, array_keys($state)));
            foreach (array_chunk($gone, 1000) as $chunk) {
                $removed += ea_tdb()->table('wh_fact_gl_line')
                    ->where('tenant_id', $this->tenant->id)
                    ->whereIn('odoo_id', array_map(fn ($i) => $this->id($i), $chunk))
                    ->delete();
            }
        }

        return [
            'checked'  => count($state),
            'fixed'    => $fixed,
            'removed'  => $removed,
            'complete' => $complete,
        ];
    }

    /**
     * Back-fill invoice_date onto lines from their parent invoice.
     * Only invoice_date moves — gl_date keeps the date the line was booked.
     */
    public function reconcileLineDates(): int
    {
        return ea_tdb()->update(
            'UPDATE wh_fact_gl_line l
               JOIN wh_fact_invoice i
                 ON i.tenant_id = l.tenant_id AND i.odoo_id = l.move_odoo_id
                SET l.invoice_date = i.invoice_date, l.move_type = i.move_type
              WHERE l.tenant_id = ?
                AND (l.invoice_date IS NULL OR l.invoice_date <> i.invoice_date OR l.move_type <> i.move_type)',
            [$this->tenant->id]
        );
    }

    private function state(string $model): object
    {
        $model = $this->source->stateModel($model);
        $row = ea_tdb()->table('wh_sync_state')
            ->where('tenant_id', $this->tenant->id)->where('model', $model)->first();
        if ($row) return $row;

        ea_tdb()->table('wh_sync_state')->insert([
            'tenant_id' => $this->tenant->id, 'model' => $model,
            'rows_synced' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ea_tdb()->table('wh_sync_state')
            ->where('tenant_id', $this->tenant->id)->where('model', $model)->first();
    }

    private function note(string $model, ?string $error): void
    {
        $model = $this->source->stateModel($model);
        ea_tdb()->table('wh_sync_state')
            ->where('tenant_id', $this->tenant->id)->where('model', $model)
            ->update(['last_error' => $error, 'updated_at' => now()]);
    }

    private function d(mixed $v): ?string
    {
        $s = is_string($v) ? substr($v, 0, 10) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function dt(mixed $v): ?string
    {
        return (is_string($v) && strlen($v) >= 19) ? substr($v, 0, 19) : null;
    }
}
