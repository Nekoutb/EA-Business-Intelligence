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
    public function syncAll(bool $full = false, ?callable $progress = null): array
    {
        $out = [];
        foreach ([
            'res.partner'            => fn () => $this->syncPartners($full),
            'account.account'        => fn () => $this->syncAccounts($full),
            'account.move'           => fn () => $this->syncInvoices($full),
            'account.move.line'      => fn () => $this->syncInvoiceLines($full),
        ] as $model => $fn) {
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

    private function syncInvoiceLines(bool $full): int
    {
        return $this->pull('account.move.line', $full,
            ['move_id', 'account_id', 'product_id', 'quantity', 'price_unit', 'discount',
             'price_subtotal', 'date', 'write_date', 'display_type'],
            function (array $r) {
                $mv = $r['move_id'] ?? null;
                return [
                    'tenant_id'       => $this->tenant->id,
                    'odoo_id'         => (int) $r['id'],
                    'move_odoo_id'    => is_array($mv) ? (int) $mv[0] : null,
                    'account_odoo_id' => is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : null,
                    'product_odoo_id' => is_array($r['product_id'] ?? null) ? (int) $r['product_id'][0] : null,
                    // `date` is the accounting date on the line and matches
                    // the parent's period; the parent's invoice_date is
                    // back-filled by the reconcile step below.
                    'invoice_date'    => $this->d($r['date'] ?? null),
                    'move_type'       => (string) ($r['display_type'] ?: ''),
                    'quantity'        => (float) ($r['quantity'] ?? 0),
                    'price_unit'      => (float) ($r['price_unit'] ?? 0),
                    'discount'        => (float) ($r['discount'] ?? 0),
                    'price_subtotal'  => (float) ($r['price_subtotal'] ?? 0),
                    'write_date'      => $this->dt($r['write_date'] ?? null),
                ];
            },
            'wh_fact_invoice_line');
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
        $state     = $this->state($model);
        $watermark = $full ? null : ($state->last_write_date ?? null);

        $domain = $extra;
        if ($watermark) {
            $domain[] = ['write_date', '>', (string) $watermark];
        }

        $rows = 0;
        $maxWrite = $watermark;
        $offset = 0;

        while (true) {
            $page = $this->odoo->searchRead($model, $domain, array_merge(['id'], $fields), [
                'offset' => $offset,
                'limit'  => self::PAGE,
                // Ordering by write_date makes the watermark meaningful even
                // if a run is interrupted mid-model.
                'order'  => 'write_date asc, id asc',
            ]);
            if ($page === []) break;

            $batch = [];
            foreach ($page as $r) {
                $batch[] = $map($r);
                $w = $this->dt($r['write_date'] ?? null);
                if ($w !== null && ($maxWrite === null || $w > $maxWrite)) $maxWrite = $w;
            }
            foreach (array_chunk($batch, self::CHUNK) as $chunk) {
                DB::table($table)->upsert(
                    $chunk,
                    ['tenant_id', 'odoo_id'],
                    array_values(array_diff(array_keys($chunk[0]), ['tenant_id', 'odoo_id']))
                );
            }

            $rows += count($page);
            if (count($page) < self::PAGE) break;
            $offset += self::PAGE;
        }

        // Advance the watermark only after every page landed.
        DB::table('wh_sync_state')->where('id', $state->id)->update([
            'last_write_date' => $maxWrite,
            'last_run_at'     => now(),
            'rows_synced'     => (int) $state->rows_synced + $rows,
            'updated_at'      => now(),
        ]);

        return $rows;
    }

    /** Back-fill invoice_date onto lines from their parent invoice. */
    public function reconcileLineDates(): int
    {
        return DB::update(
            'UPDATE wh_fact_invoice_line l
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
