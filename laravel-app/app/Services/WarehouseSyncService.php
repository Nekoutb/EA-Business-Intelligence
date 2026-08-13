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

    public function __construct(
        private OdooClient $odoo,
        private Tenant $tenant,
    ) {}

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
            'res.partner'            => fn () => $this->syncPartners($full),
            'account.account'        => fn () => $this->syncAccounts($full),
            'account.move'           => fn () => $this->syncInvoices($full),
            'account.move.line'      => fn () => $this->syncGlLines($full),
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

    // ── Dimensions ──────────────────────────────────────────────────────

    private function syncPartners(bool $full): int
    {
        return $this->pull('res.partner', $full,
            ['name', 'ref', 'is_company', 'write_date'],
            fn (array $r) => [
                'tenant_id'  => $this->tenant->id,
                'odoo_id'    => (int) $r['id'],
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
                    'odoo_id'       => (int) $r['id'],
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
        return $this->pull('account.move', $full,
            ['name', 'move_type', 'state', 'payment_state', 'invoice_date', 'invoice_date_due',
             'partner_id', 'amount_untaxed_signed', 'amount_total_signed', 'amount_residual_signed', 'write_date'],
            fn (array $r) => [
                'tenant_id'        => $this->tenant->id,
                'odoo_id'          => (int) $r['id'],
                'name'             => mb_substr((string) ($r['name'] ?: ''), 0, 64),
                'move_type'        => (string) ($r['move_type'] ?: ''),
                'state'            => (string) ($r['state'] ?: ''),
                'payment_state'    => (string) ($r['payment_state'] ?: ''),
                'invoice_date'     => $this->d($r['invoice_date'] ?? null),
                'invoice_date_due' => $this->d($r['invoice_date_due'] ?? null),
                'partner_odoo_id'  => is_array($r['partner_id'] ?? null) ? (int) $r['partner_id'][0] : null,
                'amount_untaxed'   => (float) ($r['amount_untaxed_signed'] ?? 0),
                'amount_total'     => (float) ($r['amount_total_signed'] ?? 0),
                'amount_residual'  => (float) ($r['amount_residual_signed'] ?? 0),
                'write_date'       => $this->dt($r['write_date'] ?? null),
            ],
            'wh_fact_invoice',
            // Customer + supplier documents only; journal entries are not
            // invoice facts and would distort every count.
            [['move_type', 'in', ['out_invoice', 'out_refund', 'in_invoice', 'in_refund']]]);
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
            'odoo_id'         => (int) $r['id'],
            'move_odoo_id'    => is_array($mv) ? (int) $mv[0] : null,
            'account_odoo_id' => is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : null,
            'product_odoo_id' => is_array($r['product_id'] ?? null) ? (int) $r['product_id'][0] : null,
            'journal_odoo_id' => is_array($r['journal_id'] ?? null) ? (int) $r['journal_id'][0] : null,
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
        $state  = $this->state($model);
        $cursorW = $full ? null : ($state->last_write_date ?? null);
        $cursorId = $full ? null : ($state->last_id ?? null);

        $rows = 0;

        while (true) {
            // Keyset pagination on (write_date, id). Both halves matter:
            //
            // write_date alone cannot resume precisely, because a group of
            // records sharing one write_date has to be re-read from its start
            // ('>=') or risk skipping its tail ('>'). Once such a group is
            // bigger than one run's budget, '>=' re-reads the same slice every
            // run and the sync never advances — which is exactly what a
            // 566,653-row counter against a 40,754-row table looks like.
            //
            // Pairing it with id makes the cursor unique and strictly ordered,
            // so a run resumes on the record straight after the last one read.
            // It also retires offset paging: asking Odoo for offset 400,000
            // makes it walk 400,000 rows to reach the page every time.
            $domain = $extra;
            if ($cursorW !== null) {
                if ($cursorId !== null) {
                    $domain[] = '|';
                    $domain[] = ['write_date', '>', (string) $cursorW];
                    $domain[] = '&';
                    $domain[] = ['write_date', '=', (string) $cursorW];
                    $domain[] = ['id', '>', (int) $cursorId];
                } else {
                    // Pre-cursor state: no id recorded yet, so fall back to the
                    // inclusive boundary for one run until the cursor is set.
                    $domain[] = ['write_date', '>=', (string) $cursorW];
                }
            }

            $page = $this->odoo->searchRead($model, $domain, array_merge(['id'], $fields), [
                'limit' => self::PAGE,
                // Must match the cursor exactly or the keyset walk breaks.
                'order' => 'write_date asc, id asc',
            ]);
            if ($page === []) break;

            $batch = [];
            foreach ($page as $r) {
                $batch[] = $map($r);
            }
            foreach (array_chunk($batch, self::CHUNK) as $chunk) {
                DB::table($table)->upsert(
                    $chunk,
                    ['tenant_id', 'odoo_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['tenant_id', 'odoo_id']))
                );
            }

            // Advance to the LAST row of the page — the ordering guarantees it
            // is the highest (write_date, id) seen, so this is the resume point.
            $last = $page[count($page) - 1];
            $lastW = $this->dt($last['write_date'] ?? null);
            if ($lastW !== null) {
                $cursorW  = $lastW;
                $cursorId = (int) $last['id'];
            } else {
                // No write_date to key on: stop rather than loop on the same
                // page forever. Next run re-reads from the existing cursor.
                break;
            }

            $rows += count($page);

            DB::table('wh_sync_state')->where('id', $state->id)->update([
                'last_write_date' => $cursorW,
                'last_id'         => $cursorId,
                'last_run_at'     => now(),
                'rows_synced'     => (int) $state->rows_synced + $rows,
                'updated_at'      => now(),
            ]);

            if (count($page) < self::PAGE) break;

            // Out of time — stop cleanly; the cursor marks the resume point.
            if ($this->deadline > 0 && microtime(true) >= $this->deadline) break;
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
            $ids = DB::table('wh_fact_gl_line')
                ->where('tenant_id', $this->tenant->id)
                ->where(fn ($q) => $q->whereNull('gl_date')->orWhereNull('parent_state')->orWhere('parent_state', ''))
                ->orderBy('odoo_id')
                ->limit(self::PAGE)
                ->pluck('odoo_id')
                ->all();
            if ($ids === []) break;

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
                DB::table('wh_fact_gl_line')->upsert(
                    $chunk,
                    ['tenant_id', 'odoo_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['tenant_id', 'odoo_id']))
                );
            }
            $filled += count($batch);

            // Asked for these ids and Odoo did not return them — gone upstream.
            $gone = array_values(array_diff($ids, array_keys($seen)));
            if ($gone !== []) {
                DB::table('wh_fact_gl_line')
                    ->where('tenant_id', $this->tenant->id)
                    ->whereIn('odoo_id', $gone)
                    ->delete();
                $removed += count($gone);
            }

            if ($this->deadline > 0 && microtime(true) >= $this->deadline) break;
        }

        $left = DB::table('wh_fact_gl_line')
            ->where('tenant_id', $this->tenant->id)
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
     * Back-fill invoice_date onto lines from their parent invoice.
     * Only invoice_date moves — gl_date keeps the date the line was booked.
     */
    public function reconcileLineDates(): int
    {
        return DB::update(
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
        $row = DB::table('wh_sync_state')
            ->where('tenant_id', $this->tenant->id)->where('model', $model)->first();
        if ($row) return $row;

        DB::table('wh_sync_state')->insert([
            'tenant_id' => $this->tenant->id, 'model' => $model,
            'rows_synced' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('wh_sync_state')
            ->where('tenant_id', $this->tenant->id)->where('model', $model)->first();
    }

    private function note(string $model, ?string $error): void
    {
        DB::table('wh_sync_state')
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
