<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse ingestion for direct-database ERP tenants (ERP_TYPE != odoo).
 *
 * Mirrors WarehouseSyncService but reads from the tenant's external ERP
 * database (ErpDbConnectionTester::connectionConfig) instead of the Odoo
 * API, filling the SAME wh_* tables so every warehouse-backed report works
 * unchanged.
 *
 * External keys are strings; warehouse rows are keyed on integers. The
 * wh_key_map table hands out a stable surrogate integer per (tenant,
 * entity, external key) — see its migration for rationale.
 *
 * The source (an analytics star schema) carries no change timestamps, so
 * every run is a full refresh followed by a deletion pass. Volumes are
 * dimension/fact extracts (hundreds to a few thousand rows), so a full
 * pass costs seconds, not minutes.
 *
 * First mapping: Elimelec (TS Consulting star schema, French naming) —
 * see docs/elimelec-bi-schema.md. The queries only reference columns
 * documented there.
 */
final class ErpDbSyncService
{
    private const CHUNK = 500;

    private string $connection;

    /** @var array<string, array<string, int>> entity => ext_key => surrogate */
    private array $keyCache = [];

    public function __construct(private Tenant $tenant)
    {
        $this->connection = 'erp_sync_' . $tenant->id;
        config(["database.connections.{$this->connection}" => ErpDbConnectionTester::connectionConfig()]);
    }

    /**
     * Sync every model for this tenant.
     *
     * @return array<string, array{rows:int, seconds:float, error:?string}>
     */
    public function syncAll(?callable $progress = null): array
    {
        $out = [];
        foreach ([
            'erp:clients'       => fn () => $this->syncClients(),
            'erp:invoices'      => fn () => $this->syncInvoices(),
            'erp:invoice_lines' => fn () => $this->syncInvoiceLines(),
        ] as $model => $fn) {
            $t0 = microtime(true);
            try {
                $rows = $fn();
                $out[$model] = ['rows' => $rows, 'seconds' => round(microtime(true) - $t0, 1), 'error' => null];
            } catch (\Throwable $e) {
                $out[$model] = ['rows' => 0, 'seconds' => round(microtime(true) - $t0, 1), 'error' => $e->getMessage()];
            }
            $this->note($model, $out[$model]);
            if ($progress) $progress($model, $out[$model]);
        }
        DB::purge($this->connection);
        return $out;
    }

    // ── Dimensions ──────────────────────────────────────────────────────

    private function syncClients(): int
    {
        $rows = DB::connection($this->connection)->select(
            'select cle_client, code_client, raison_sociale, nom_commercial from dim_client'
        );

        $batch = [];
        $seen  = [];
        foreach ($rows as $c) {
            $id = $this->keyId('partner', (string) $c->cle_client);
            $seen[] = $id;
            $batch[] = [
                'tenant_id'  => $this->tenant->id,
                'odoo_id'    => $id,
                'name'       => trim((string) ($c->raison_sociale ?: $c->nom_commercial ?: $c->cle_client)),
                'ref'        => $c->code_client !== null ? substr((string) $c->code_client, 0, 64) : null,
                'is_company' => true,
                'write_date' => now(),
            ];
        }
        $this->flush('wh_dim_partner', $batch, ['name', 'ref', 'is_company', 'write_date']);
        $this->prune('wh_dim_partner', $seen);
        return count($batch);
    }

    // ── Facts ───────────────────────────────────────────────────────────

    private function syncInvoices(): int
    {
        // Payment terms live on the client; the due date is derived because
        // the source has no explicit one. reste_du is authoritative for the
        // open balance (the payment fact tables are barely populated —
        // ~5 rows vs 1,255 invoices as of discovery).
        $rows = DB::connection($this->connection)->select(
            'select f.cle_facture, f.code_facture, f.reference, f.cle_client, f.jour_facture,
                    f.montant_ht, f.montant_ttc, f.montant_encaisse, f.reste_du,
                    c.delai_paiement_jours
             from fait_facture_generique f
             left join dim_client c on c.cle_client = f.cle_client'
        );

        $batch = [];
        $seen  = [];
        foreach ($rows as $f) {
            $id = $this->keyId('invoice', (string) $f->cle_facture);
            $seen[] = $id;

            $date = $f->jour_facture ? substr((string) $f->jour_facture, 0, 10) : null;
            $due  = null;
            if ($date !== null) {
                $days = max(0, (int) ($f->delai_paiement_jours ?? 0));
                $due  = date('Y-m-d', strtotime($date . " +{$days} days"));
            }

            $residual  = (float) ($f->reste_du ?? 0);
            $collected = (float) ($f->montant_encaisse ?? 0);

            $batch[] = [
                'tenant_id'        => $this->tenant->id,
                'odoo_id'          => $id,
                'name'             => substr((string) ($f->code_facture ?: $f->reference ?: $f->cle_facture), 0, 64),
                'move_type'        => 'out_invoice',
                'state'            => 'posted',
                'payment_state'    => $residual <= 0.005 ? 'paid' : ($collected > 0.005 ? 'partial' : 'not_paid'),
                'invoice_date'     => $date,
                'invoice_date_due' => $due,
                'partner_odoo_id'  => $f->cle_client !== null ? $this->keyId('partner', (string) $f->cle_client) : null,
                'amount_untaxed'   => (float) ($f->montant_ht ?? 0),
                'amount_total'     => (float) ($f->montant_ttc ?? 0),
                'amount_residual'  => $residual,
                'write_date'       => now(),
            ];
        }
        $this->flush('wh_fact_invoice', $batch, [
            'name', 'move_type', 'state', 'payment_state', 'invoice_date', 'invoice_date_due',
            'partner_odoo_id', 'amount_untaxed', 'amount_total', 'amount_residual', 'write_date',
        ]);
        $this->prune('wh_fact_invoice', $seen);
        return count($batch);
    }

    private function syncInvoiceLines(): int
    {
        $rows = DB::connection($this->connection)->select(
            'select cle_ligne_facture, cle_facture, jour_facture, categorie,
                    quantite, prix_unitaire_ht, montant_ht
             from fait_facture_generique_ligne'
        );

        $batch = [];
        $seen  = [];
        foreach ($rows as $l) {
            $id = $this->keyId('invoice_line', (string) $l->cle_ligne_facture);
            $seen[] = $id;
            $batch[] = [
                'tenant_id'       => $this->tenant->id,
                'odoo_id'         => $id,
                'move_odoo_id'    => $l->cle_facture !== null ? $this->keyId('invoice', (string) $l->cle_facture) : null,
                'account_odoo_id' => null,
                // The source has no product dimension; the service category
                // is the closest grouping axis, mapped through its own
                // surrogate so category-level rollups stay possible.
                'product_odoo_id' => $l->categorie !== null && $l->categorie !== ''
                    ? $this->keyId('category', (string) $l->categorie) : null,
                'invoice_date'    => $l->jour_facture ? substr((string) $l->jour_facture, 0, 10) : null,
                'move_type'       => 'out_invoice',
                'quantity'        => (float) ($l->quantite ?? 0),
                'price_unit'      => (float) ($l->prix_unitaire_ht ?? 0),
                'discount'        => 0,
                'price_subtotal'  => (float) ($l->montant_ht ?? 0),
                'write_date'      => now(),
            ];
        }
        $this->flush('wh_fact_invoice_line', $batch, [
            'move_odoo_id', 'account_odoo_id', 'product_odoo_id', 'invoice_date',
            'move_type', 'quantity', 'price_unit', 'discount', 'price_subtotal', 'write_date',
        ]);
        $this->prune('wh_fact_invoice_line', $seen);
        return count($batch);
    }

    // ── Plumbing ────────────────────────────────────────────────────────

    /** Stable surrogate integer for an external key (see wh_key_map). */
    private function keyId(string $entity, string $extKey): int
    {
        if (isset($this->keyCache[$entity][$extKey])) {
            return $this->keyCache[$entity][$extKey];
        }
        // Warm the whole entity on first touch — one query instead of N.
        if (!isset($this->keyCache[$entity])) {
            $this->keyCache[$entity] = DB::table('wh_key_map')
                ->where('tenant_id', $this->tenant->id)->where('entity', $entity)
                ->pluck('id', 'ext_key')->map(fn ($v) => (int) $v)->all();
            if (isset($this->keyCache[$entity][$extKey])) {
                return $this->keyCache[$entity][$extKey];
            }
        }
        try {
            $id = (int) DB::table('wh_key_map')->insertGetId([
                'tenant_id'  => $this->tenant->id,
                'entity'     => $entity,
                'ext_key'    => substr($extKey, 0, 191),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL's collation-insensitive unique index can equate keys the
            // PHP cache keeps apart ('Debours' vs 'Débours'). Adopt the row
            // the database considers equal — mapping both spellings to one
            // surrogate is also the semantically right rollup.
            $id = (int) DB::table('wh_key_map')
                ->where('tenant_id', $this->tenant->id)
                ->where('entity', $entity)
                ->where('ext_key', substr($extKey, 0, 191))
                ->value('id');
            if ($id <= 0) throw $e;
        }
        return $this->keyCache[$entity][$extKey] = $id;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function flush(string $table, array $rows, array $updateCols): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, ['tenant_id', 'odoo_id'], $updateCols);
        }
    }

    /**
     * Remove warehouse rows that vanished from the source. Safe here
     * because a direct-DB tenant's warehouse rows come ONLY from this
     * sync (never mixed with Odoo rows).
     *
     * @param int[] $keep surrogate ids seen in this run
     */
    private function prune(string $table, array $keep): void
    {
        $q = DB::table($table)->where('tenant_id', $this->tenant->id);
        if ($keep === []) {
            // An empty source extract is more likely a provider-side reload
            // than a genuine "everything was deleted" — keep existing rows.
            return;
        }
        // Chained NOT INs AND together, i.e. "not in the union of chunks".
        // MySQL handles a few thousand ids fine at these volumes; revisit
        // if a source ever grows past ~100k rows.
        foreach (array_chunk($keep, 5000) as $chunk) {
            $q->whereNotIn('odoo_id', $chunk);
        }
        $q->delete();
    }

    /** Record the outcome in wh_sync_state (same table the Odoo sync uses). */
    private function note(string $model, array $result): void
    {
        DB::table('wh_sync_state')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'model' => $model],
            [
                'last_run_at' => now(),
                'rows_synced' => (int) $result['rows'],
                'last_error'  => $result['error'],
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }
}
