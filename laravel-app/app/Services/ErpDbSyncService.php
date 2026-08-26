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
            'erp:dossiers'      => fn () => $this->syncDossiers(),
            'erp:advances'      => fn () => $this->syncAdvances(),
            'erp:validations'   => fn () => $this->syncValidations(),
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

        $this->ensureKeys('partner', array_map(fn ($c) => (string) $c->cle_client, $rows));

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
            'select f.cle_facture, f.code_facture, f.reference, f.cle_client, f.cle_dossier, f.jour_facture,
                    f.montant_ht, f.montant_ttc, f.montant_encaisse, f.reste_du,
                    c.delai_paiement_jours, lf.fee_ht
             from fait_facture_generique f
             left join dim_client c on c.cle_client = f.cle_client
             left join (
               select cle_facture, sum(montant_ht) filter (where est_debours is not true) fee_ht
               from fait_facture_generique_ligne group by cle_facture
             ) lf on lf.cle_facture = f.cle_facture'
        );

        $this->ensureKeys('invoice', array_map(fn ($f) => (string) $f->cle_facture, $rows));
        $this->ensureKeys('partner', array_map(fn ($f) => (string) ($f->cle_client ?? ''), $rows));
        $this->ensureKeys('dossier', array_map(fn ($f) => (string) ($f->cle_dossier ?? ''), $rows));

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
                'dossier_odoo_id'  => $f->cle_dossier !== null ? $this->keyId('dossier', (string) $f->cle_dossier) : null,
                // REVENUE = the fee commission only. Débours on the invoice
                // are rebilled out-of-pocket costs, not revenue — so the
                // warehouse revenue figure (amount_untaxed, read by the
                // Dashboard / Revenue / Customer Insights pages) carries the
                // fee portion of the lines. Invoices without lines fall back
                // to the full HT. TTC and the open balance stay complete:
                // the client owes débours too.
                'amount_untaxed'   => (float) ($f->fee_ht ?? $f->montant_ht ?? 0),
                'amount_total'     => (float) ($f->montant_ttc ?? 0),
                'amount_residual'  => $residual,
                'write_date'       => now(),
            ];
        }
        $this->flush('wh_fact_invoice', $batch, [
            'name', 'move_type', 'state', 'payment_state', 'invoice_date', 'invoice_date_due',
            'partner_odoo_id', 'dossier_odoo_id', 'amount_untaxed', 'amount_total', 'amount_residual', 'write_date',
        ]);
        $this->prune('wh_fact_invoice', $seen);
        return count($batch);
    }

    private function syncInvoiceLines(): int
    {
        $rows = DB::connection($this->connection)->select(
            'select cle_ligne_facture, cle_facture, jour_facture, categorie, libelle, est_debours,
                    quantite, prix_unitaire_ht, montant_ht
             from fait_facture_generique_ligne'
        );

        $this->ensureKeys('invoice_line', array_map(fn ($l) => (string) $l->cle_ligne_facture, $rows));
        $this->ensureKeys('invoice', array_map(fn ($l) => (string) ($l->cle_facture ?? ''), $rows));
        $this->ensureKeys('category', array_map(fn ($l) => (string) ($l->categorie ?? ''), $rows));

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
                'is_debours'      => (bool) ($l->est_debours ?? false),
                'label'           => $l->libelle !== null ? mb_substr((string) $l->libelle, 0, 96) : null,
                'write_date'      => now(),
            ];
        }
        $this->flush('wh_fact_invoice_line', $batch, [
            'move_odoo_id', 'account_odoo_id', 'product_odoo_id', 'invoice_date',
            'move_type', 'quantity', 'price_unit', 'discount', 'price_subtotal',
            'is_debours', 'label', 'write_date',
        ]);
        $this->prune('wh_fact_invoice_line', $seen);
        return count($batch);
    }

    // ── Logistics facts (dossier-based tenants) ─────────────────────────

    private function syncDossiers(): int
    {
        $rows = DB::connection($this->connection)->select(
            'select f.cle_dossier, f.code_dossier, f.cle_client, f.jour_ouverture, f.jour_cloture,
                    f.est_clos, f.est_suspendu, f.delai_traitement_jours, f.delai_facturation_jours,
                    f.poids_net, f.nombre_conteneurs, f.nombre_colis, f.valeur_marchandise,
                    f.regime_douanier, f.incoterm, f.type_embarquement, f.importateur,
                    c.raison_sociale client_name, t.nom type_name, p.nom port_name, e.nom_complet employee_name
             from fait_dossier_transit f
             left join dim_client c on c.cle_client = f.cle_client
             left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
             left join dim_port p on p.cle_port = f.cle_port
             left join dim_employe e on e.cle_employe = f.cle_employe_suiveur'
        );
        $this->ensureKeys('dossier', array_map(fn ($r) => (string) $r->cle_dossier, $rows));
        $this->ensureKeys('partner', array_map(fn ($r) => (string) ($r->cle_client ?? ''), $rows));

        $batch = []; $seen = [];
        foreach ($rows as $r) {
            $id = $this->keyId('dossier', (string) $r->cle_dossier);
            $seen[] = $id;
            $batch[] = [
                'tenant_id'        => $this->tenant->id,
                'odoo_id'          => $id,
                'code'             => $r->code_dossier !== null ? substr((string) $r->code_dossier, 0, 64) : null,
                'partner_odoo_id'  => $r->cle_client !== null ? $this->keyId('partner', (string) $r->cle_client) : null,
                'client_name'      => $r->client_name !== null ? mb_substr((string) $r->client_name, 0, 191) : null,
                'type_name'        => $r->type_name !== null ? mb_substr((string) $r->type_name, 0, 128) : null,
                'port_name'        => $r->port_name !== null ? mb_substr((string) $r->port_name, 0, 128) : null,
                'employee_name'    => $r->employee_name !== null ? mb_substr((string) $r->employee_name, 0, 128) : null,
                'opened_date'      => $r->jour_ouverture ? substr((string) $r->jour_ouverture, 0, 10) : null,
                'closed_date'      => $r->jour_cloture ? substr((string) $r->jour_cloture, 0, 10) : null,
                'is_closed'        => (bool) ($r->est_clos ?? false),
                'is_suspended'     => (bool) ($r->est_suspendu ?? false),
                'lead_days'        => $r->delai_traitement_jours,
                'billing_lag_days' => $r->delai_facturation_jours,
                'weight_kg'        => $r->poids_net,
                'containers'       => $r->nombre_conteneurs,
                'packages'         => $r->nombre_colis,
                'cargo_value'      => $r->valeur_marchandise,
                'regime'           => $r->regime_douanier !== null ? substr((string) $r->regime_douanier, 0, 64) : null,
                'incoterm'         => $r->incoterm !== null ? substr((string) $r->incoterm, 0, 32) : null,
                'embarquement'     => $r->type_embarquement !== null ? substr((string) $r->type_embarquement, 0, 64) : null,
                'importer'         => $r->importateur !== null ? mb_substr((string) $r->importateur, 0, 191) : null,
                'write_date'       => now(),
            ];
        }
        $this->flush('wh_fact_dossier', $batch, [
            'code', 'partner_odoo_id', 'client_name', 'type_name', 'port_name', 'employee_name',
            'opened_date', 'closed_date', 'is_closed', 'is_suspended', 'lead_days', 'billing_lag_days',
            'weight_kg', 'containers', 'packages', 'cargo_value', 'regime', 'incoterm', 'embarquement',
            'importer', 'write_date',
        ]);
        $this->prune('wh_fact_dossier', $seen);
        return count($batch);
    }

    private function syncAdvances(): int
    {
        $rows = DB::connection($this->connection)->select(
            "select a.cle_ligne_avance, a.cle_dossier, a.libelle_ligne, a.montant,
                    a.jour_demande, a.jour_decaissement, a.delai_decaissement_jours, a.est_justifiee,
                    coalesce(s.raison_sociale, nullif(a.nom_beneficiaire,'')) who
             from fait_avance_frais a
             left join dim_fournisseur s on s.cle_fournisseur = a.cle_fournisseur
             where a.est_decaissee"
        );
        $this->ensureKeys('advance', array_map(fn ($r) => (string) $r->cle_ligne_avance, $rows));
        $this->ensureKeys('dossier', array_map(fn ($r) => (string) ($r->cle_dossier ?? ''), $rows));

        $batch = []; $seen = [];
        foreach ($rows as $r) {
            $id = $this->keyId('advance', (string) $r->cle_ligne_avance);
            $seen[] = $id;
            $batch[] = [
                'tenant_id'             => $this->tenant->id,
                'odoo_id'               => $id,
                'dossier_odoo_id'       => $r->cle_dossier !== null ? $this->keyId('dossier', (string) $r->cle_dossier) : null,
                'supplier_name'         => $r->who !== null ? mb_substr((string) $r->who, 0, 191) : null,
                'label'                 => $r->libelle_ligne !== null ? mb_substr((string) $r->libelle_ligne, 0, 96) : null,
                'amount'                => (float) ($r->montant ?? 0),
                'requested_date'        => $r->jour_demande ? substr((string) $r->jour_demande, 0, 10) : null,
                'disbursed_date'        => $r->jour_decaissement ? substr((string) $r->jour_decaissement, 0, 10) : null,
                'disbursement_lag_days' => $r->delai_decaissement_jours,
                'is_justified'          => (bool) ($r->est_justifiee ?? false),
                'write_date'            => now(),
            ];
        }
        $this->flush('wh_fact_advance', $batch, [
            'dossier_odoo_id', 'supplier_name', 'label', 'amount', 'requested_date',
            'disbursed_date', 'disbursement_lag_days', 'is_justified', 'write_date',
        ]);
        $this->prune('wh_fact_advance', $seen);
        return count($batch);
    }

    private function syncValidations(): int
    {
        $rows = DB::connection($this->connection)->select(
            'select v.cle_etape_validation, v.cle_document, v.rang_etape, v.jour_document, v.jour_etape,
                    v.delai_depuis_precedente_jours, v.delai_total_circuit_jours, v.est_franchie,
                    td.libelle doc_type, w.nom_circuit circuit
             from fait_validation v
             left join dim_type_document_valide td on td.cle_type_document = v.cle_type_document
             left join dim_etape_workflow w on w.cle_etape_workflow = v.cle_etape_workflow'
        );
        $this->ensureKeys('validation', array_map(fn ($r) => (string) $r->cle_etape_validation, $rows));

        $batch = []; $seen = [];
        foreach ($rows as $r) {
            $id = $this->keyId('validation', (string) $r->cle_etape_validation);
            $seen[] = $id;
            $batch[] = [
                'tenant_id'     => $this->tenant->id,
                'odoo_id'       => $id,
                'doc_key'       => $r->cle_document !== null ? substr((string) $r->cle_document, 0, 96) : null,
                'doc_type'      => $r->doc_type !== null ? mb_substr((string) $r->doc_type, 0, 96) : null,
                'circuit'       => $r->circuit !== null ? mb_substr((string) $r->circuit, 0, 96) : null,
                'rang'          => $r->rang_etape,
                'doc_date'      => $r->jour_document ? substr((string) $r->jour_document, 0, 10) : null,
                'step_date'     => $r->jour_etape ? substr((string) $r->jour_etape, 0, 10) : null,
                'step_lag_days' => $r->delai_depuis_precedente_jours,
                'total_days'    => $r->delai_total_circuit_jours,
                'is_passed'     => (bool) ($r->est_franchie ?? false),
                'write_date'    => now(),
            ];
        }
        $this->flush('wh_fact_validation', $batch, [
            'doc_key', 'doc_type', 'circuit', 'rang', 'doc_date', 'step_date',
            'step_lag_days', 'total_days', 'is_passed', 'write_date',
        ]);
        $this->prune('wh_fact_validation', $seen);
        return count($batch);
    }

    // ── Plumbing ────────────────────────────────────────────────────────

    /**
     * Bulk-allocate surrogates for every external key of an entity in three
     * queries (load, insertOrIgnore missing, reload) instead of one insert
     * per new key — the per-key path pushed a first full sync past
     * Cloudflare's ~100s ceiling. insertOrIgnore also absorbs
     * collation-equal duplicates ('Debours' vs 'Débours') silently; the
     * reload then maps both spellings to the surviving row.
     *
     * @param string[] $extKeys
     */
    private function ensureKeys(string $entity, array $extKeys): void
    {
        if (!isset($this->keyCache[$entity])) {
            $this->keyCache[$entity] = DB::table('wh_key_map')
                ->where('tenant_id', $this->tenant->id)->where('entity', $entity)
                ->pluck('id', 'ext_key')->map(fn ($v) => (int) $v)->all();
        }
        $missing = [];
        foreach (array_unique($extKeys) as $k) {
            $k = substr((string) $k, 0, 191);
            if ($k !== '' && !isset($this->keyCache[$entity][$k])) {
                $missing[$k] = [
                    'tenant_id'  => $this->tenant->id,
                    'entity'     => $entity,
                    'ext_key'    => $k,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if ($missing === []) return;
        foreach (array_chunk(array_values($missing), self::CHUNK) as $chunk) {
            DB::table('wh_key_map')->insertOrIgnore($chunk);
        }
        $this->keyCache[$entity] = DB::table('wh_key_map')
            ->where('tenant_id', $this->tenant->id)->where('entity', $entity)
            ->pluck('id', 'ext_key')->map(fn ($v) => (int) $v)->all();
    }

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
