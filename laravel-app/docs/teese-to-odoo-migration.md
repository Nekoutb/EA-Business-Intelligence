# Teese → Odoo migration plan (Elimelec)

Teese is Elimelec's legacy transit/customs system (TS Consulting), exposed
to the BMS as PostgreSQL database `teese_elimelec_bi`, schema `bi` — 28
relations, fully catalogued in `elimelec-bi-schema.md`. This document maps
every Teese table to its Odoo destination and records the extraction
status.

## Extraction status

| Source | Status |
|---|---|
| Live Teese server (51.75.133.104:49240) | **DOWN since ~2026-08-29** (TCP timeout from BMS server and elsewhere). `op=teesedump&mode=live` is ready and dumps every visible schema/table + column catalog the moment it answers. |
| BMS warehouse copy (synced to 2026-08-26) | Available now via `op=teesedump&mode=warehouse`: partners, invoices, invoice lines, dossiers, advances, validations. |
| `bi` schema catalog | `docs/elimelec-bi-schema.md` — all 28 relations, every column. |

**Caveat:** `bi` is a reporting layer TS Consulting built for the BMS. The
live dump enumerates everything the read-only login can see; if only `bi`
is granted, ask TS Consulting to widen the grant (or export the raw
operational schema) so the migration starts from primary data, not views.

## Table mapping — masters (dimensions)

| Teese | Odoo model | Notes |
|---|---|---|
| dim_client (190) | `res.partner` (customer_rank>0) | raison_sociale→name, numero_contribuable→vat (NIU), registre_commerce→company_registry, plafond_credit→credit limit, delai_paiement_jours→payment term, est_bloque→blacklist/active |
| dim_fournisseur (117) | `res.partner` (supplier_rank>0) | transitaire/acconier/assureur/transporteur flags → `res.partner.category` tags |
| dim_employe (32) | `hr.employee` | matricule→identification_id; cle_departement→department |
| dim_departement (11) | `hr.department` | keep parent hierarchy |
| dim_utilisateur (37) | `res.users` | only active users worth creating; map to employees |
| dim_caisse (4) | `account.journal` type=cash | solde_maximum has no Odoo equivalent — note in journal description |
| dim_compte_bancaire (4) | `res.partner.bank` + `account.journal` type=bank | |
| dim_site (2) / dim_point_vente (2) | analytic plan "Site" (`account.analytic.account`) | Odoo has no operating units in standard; analytic is the durable equivalent |
| dim_port (4) | custom `transit.port` (tiny model) or partner tags | ports don't exist in standard Odoo |
| dim_rubrique_budgetaire (7) | analytic plan "Rubrique" + est_charge_fixe tag | budget AMOUNTS were never exposed in `bi` |
| dim_type_dossier (5) / dim_categorie_dossier (6) | fields on the custom dossier model | carry target lead times (delai_traitement/facturation_cible) |
| dim_etape_dossier (78) | stages of the dossier workflow | see fait_etape_dossier below |
| dim_etape_workflow (4) / dim_type_document_valide (2) | Odoo approval flows (Studio/base.automation) | configuration, not data — rebuild, don't import |
| dim_date (6,209) | not migrated | Odoo derives calendars |

## Table mapping — transactions (facts)

| Teese | Odoo model | Notes |
|---|---|---|
| fait_dossier_transit (3,643) | **custom `transit.dossier`** (recommended) or `project.task` | The business core: code, client, type, port, open/close dates, tonnage, containers, customs regime, incoterm. A small custom module keeps the domain vocabulary; each dossier also gets an analytic account so costs and revenue tie back to it |
| fait_etape_dossier (12,375) | stage history on the dossier (o2m `transit.dossier.step`) | planned vs actual per step — this is where the latency reporting lives |
| fait_facture_generique (1,255) | `account.move` out_invoice | date_facture→invoice_date; import as posted; `reste_du` reproduced via payment/opening entries, NOT typed in |
| fait_facture_generique_ligne (6,286) | `account.move.line` | `est_debours` lines → a "Débours" service product posting to a pass-through account (OHADA 47x); fee lines → "Honoraires" products by categorie |
| fait_paiement_client_entete (6) + fait_paiement_client (5) | `account.payment` + reconciliation | near-empty in Teese — collections truth is `montant_encaisse` on the invoice; expect to reconstruct payments from that + cash movements |
| fait_avance_frais (7,701) | vendor bills / `account.move` misc on the dossier's analytic account | the débours-request workflow itself (request→approve→disburse→justify) maps to purchase/expense approval flows |
| fait_mouvement_caisse (6,207) | `account.bank.statement.line` on cash journals | |
| fait_demande_achat (49) | `purchase.requisition` (or draft PO) | tiny — module recently started in Teese |
| fait_bon_commande (45) | `purchase.order` | |
| fait_validation (10,611) | not migrated as data | approval history; keep the CSV as an archive, rebuild circuits as Odoo approvals |

## Migration order

1. Chart of accounts (OHADA/SYSCOHADA localization) + journals (cash tills, banks, sales).
2. Partners (clients + fournisseurs, merged on name/NIU), employees/departments.
3. Products: "Honoraires" per invoice-line categorie + "Débours" pass-through.
4. Custom transit module: dossier + steps + ports; analytic plans (site, rubrique, dossier).
5. Dossiers with step history.
6. Invoice history as posted moves (full 2026 at minimum) + opening AR so `reste_du` totals reconcile to Teese's 2.98 Md XAF.
7. Advances / cash movements / purchases.
8. Reconciliation report: Teese totals vs Odoo totals per table before go-live.

## Open decisions for Elimelec

- How much history: full archive vs current year + open items (recommended minimum: all of 2026 + all open dossiers whatever their age).
- Whether the débours workflow runs in Odoo purchases, expenses, or a custom flow on the dossier.
- GL: Teese exposed **no general ledger** — opening balances must come from
  their accountant's trial balance, not from this extraction.
