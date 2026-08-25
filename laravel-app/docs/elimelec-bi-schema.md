# Elimelec external BI schema (`teese_elimelec_bi`, schema `bi`)

Discovered 2026-08-26 via ops `op=erpdiscover` (read-only, information_schema).
Server: PostgreSQL 15.19 at 51.75.133.104:49240, sslmode=require (self-signed).
Provider: TS Consulting (lionelkuate@tsconsulting.cm).

Star schema, French naming. Elimelec is a transit / customs-clearing business:
the core operational object is the **dossier de transit** (clearing file), which
gets invoiced through generic invoices and collected via client payments.

## Relations (28)

| Relation | Kind | ~Rows | Purpose |
|---|---|---|---|
| dim_caisse | dim | 4 | Cash tills |
| dim_categorie_dossier | dim | 6 | Dossier categories |
| dim_client | dim | 190 | Customers (NIU, credit limit, payment terms) |
| dim_compte_bancaire | dim | 4 | Bank accounts |
| dim_date | dim | 6,209 | Calendar |
| dim_departement | dim | 11 | Departments (hierarchical) |
| dim_employe | dim | 32 | Employees |
| dim_etape_dossier | dim | 78 | Dossier workflow steps (target lead times) |
| dim_etape_workflow | dim | 4 | Document validation circuits |
| dim_fournisseur | dim | 117 | Suppliers (transitaire/acconier/assureur flags) |
| dim_point_vente | dim | 2 | Points of sale |
| dim_port | dim | 4 | Ports |
| dim_rubrique_budgetaire | dim | 7 | Budget lines (hierarchical, fixed-cost flag) |
| dim_site | dim | 2 | Sites |
| dim_type_document_valide | dim | 2 | Validated document types |
| dim_type_dossier | dim | 5 | Dossier types (target processing/invoicing days) |
| dim_utilisateur | dim | 37 | System users |
| fait_avance_frais | fact | 7,701 | Expense advances (per line: caisse/bank, rubrique, supplier, dossier, disbursement lag, justification flags) |
| fait_bon_commande | fact | 45 | Purchase orders (HT/TTC/tax, delivery lag) |
| fait_demande_achat | fact | 49 | Purchase requests (validation lag) |
| fait_dossier_transit | fact | 3,643 | Transit dossiers (open/close dates, lead times, tonnage, containers, customs regime) |
| fait_etape_dossier | fact | 12,375 | Dossier step completion (planned vs actual, slippage) |
| fait_facture_generique | fact | 1,255 | Invoices (HT/TTC/TVA, collected, outstanding `reste_du`, émission→dépôt→encaissement lags, fiscal flag) |
| fait_facture_generique_ligne | fact | 6,286 | Invoice lines (category, qty, unit price, `est_debours` = pass-through disbursement) |
| fait_mouvement_caisse | fact | 6,207 | Cash till movements (in/out) |
| fait_paiement_client | fact | 5 | Payment→invoice imputations |
| fait_paiement_client_entete | fact | 6 | Client payment headers (collected, imputed, unapplied) |
| fait_validation | fact | 10,611 | Document validation steps (circuit lags) |

## Full column list

```
[dim_caisse]                cle_caisse, nom, description, solde_maximum, est_actif
[dim_categorie_dossier]     cle_categorie_dossier, nom, est_actif
[dim_client]                cle_client, code_client, raison_sociale, nom_commercial,
                            registre_commerce, numero_contribuable, ville_adresse,
                            telephone, email, categorie, plafond_credit,
                            delai_paiement_jours, delai_facturation_jours, est_bloque,
                            facture_obligatoire, retenue_source_possible, est_actif
[dim_compte_bancaire]       cle_compte_bancaire, numero_compte, banque, est_actif
[dim_date]                  date_jour, annee, trimestre, mois, libelle_mois, semaine,
                            jour_du_mois, jour_de_semaine, libelle_jour, est_week_end,
                            debut_mois, fin_mois
[dim_departement]           cle_departement, nom, abreviation, niveau,
                            cle_departement_parent, est_actif
[dim_employe]               cle_employe, matricule, nom, prenom, nom_complet, profession,
                            cle_departement, en_service, est_actif
[dim_etape_dossier]         cle_etape_dossier, nom, cle_type_dossier, rang_etape,
                            delai_cible_jours, est_bloquante
[dim_etape_workflow]        cle_etape_workflow, cle_type_document, nom_circuit,
                            version_circuit, rang_etape, niveau_hierarchique,
                            est_automatique, cle_site, cle_departement, role
[dim_fournisseur]           cle_fournisseur, numero_fournisseur, raison_sociale, categorie,
                            regime_fiscal, delai_paiement_jours, plafond_dette,
                            est_transitaire, est_acconier, est_assureur, est_transporteur,
                            est_proprietaire, est_administratif, est_actif
[dim_point_vente]           cle_point_vente, nom, cle_site, est_actif
[dim_port]                  cle_port, nom, ville, est_actif
[dim_rubrique_budgetaire]   cle_rubrique, nom, type, niveau, cle_rubrique_parent,
                            est_charge_fixe, est_actif
[dim_site]                  cle_site, nom, abreviation, ville, est_actif
[dim_type_document_valide]  cle_type_document, libelle
[dim_type_dossier]          cle_type_dossier, nom, type_activite, delai_traitement_cible,
                            delai_facturation_cible, est_actif
[dim_utilisateur]           cle_utilisateur, identifiant, nom_complet, est_actif

[fait_avance_frais]         cle_ligne_avance, cle_avance, code_avance, libelle_ligne,
                            cle_caisse, cle_compte_bancaire, cle_rubrique, cle_fournisseur,
                            cle_employe_beneficiaire, nom_beneficiaire, cle_departement,
                            cle_site, cle_dossier, cle_utilisateur_creation,
                            cle_utilisateur_decaissement, date_demande, jour_demande,
                            date_decaissement, jour_decaissement, jour_operation, montant,
                            quantite, montant_unitaire, delai_decaissement_jours,
                            mode_paiement, est_validee, est_decaissee, est_imputee,
                            est_justifiee, est_remboursable
[fait_bon_commande]         cle_ligne_commande, cle_commande, code_commande,
                            cle_fournisseur, cle_rubrique, cle_utilisateur_creation,
                            date_commande, jour_commande, date_livraison, jour_livraison,
                            libelle, quantite, prix_unitaire_ht, montant_ht, montant_ttc,
                            montant_taxe, taux_taxe, remise, statut, type_commande,
                            est_validee, delai_livraison_jours, delai_demande_commande_jours
[fait_demande_achat]        cle_ligne_demande, cle_demande, code_demande, numero_feuille,
                            cle_departement, cle_employe_demandeur, cle_rubrique,
                            cle_utilisateur_creation, date_demande, jour_demande, libelle,
                            quantite_demandee, type_demande, statut, est_validee,
                            delai_validation_jours
[fait_dossier_transit]      cle_dossier, code_dossier, numero_repertoire, cle_client,
                            cle_type_dossier, cle_categorie_dossier, cle_port,
                            cle_point_vente, cle_acconier, cle_assureur,
                            cle_employe_suiveur, cle_utilisateur_creation, date_ouverture,
                            jour_ouverture, date_cloture, jour_cloture,
                            date_debut_facturation, date_suspension,
                            delai_traitement_jours, delai_facturation_jours, poids_net,
                            poids_brut, volume, nombre_colis, nombre_conteneurs,
                            valeur_marchandise, valeur_taxable, regime_douanier,
                            type_embarquement, type_conteneur, incoterm, importateur,
                            est_clos, est_suspendu
[fait_etape_dossier]        cle_fait_etape, cle_dossier, cle_etape_dossier,
                            cle_type_dossier, cle_utilisateur_validation, rang_etape,
                            date_prevue, jour_prevu, date_reelle, jour_reel, ecart_jours,
                            duree_depuis_precedente_jours, est_franchie, est_invalidee
[fait_facture_generique]    cle_facture, code_facture, reference, cle_client,
                            cle_point_vente, cle_dossier, cle_utilisateur_creation,
                            date_facture, jour_facture, date_sortie, date_depot,
                            date_retour_recu, montant_ht, montant_ttc, montant_tva,
                            avance_non_payee, tva_avance_non_payee, montant_encaisse,
                            reste_du, delai_emission_depot_jours,
                            delai_depot_encaissement_jours, a_but_fiscal
[fait_facture_generique_ligne] cle_ligne_facture, cle_facture, cle_client, jour_facture,
                            libelle, categorie, unite, quantite, prix_unitaire_ht,
                            prix_unitaire_ttc, montant_ht, montant_ttc, taux_taxe,
                            est_debours
[fait_mouvement_caisse]     cle_mouvement, origine, cle_caisse, cle_utilisateur,
                            code_piece, libelle, beneficiaire, date_operation,
                            jour_operation, entree, sortie, est_confirme
[fait_paiement_client]      cle_imputation, cle_paiement, cle_facture, type_facture,
                            cle_client, date_paiement, jour_paiement, montant_impute
[fait_paiement_client_entete] cle_paiement, code_paiement, cle_client, cle_point_vente,
                            cle_dossier, cle_utilisateur, date_paiement, jour_paiement,
                            montant_encaisse, montant_impute, reliquat_non_impute,
                            destination_paiement
[fait_validation]           cle_etape_validation, cle_document, cle_type_document,
                            cle_etape_workflow, cle_site, rang_etape, date_document,
                            jour_document, date_etape, jour_etape,
                            delai_depuis_precedente_jours, delai_total_circuit_jours,
                            est_validee, est_franchie, observation
```

## What this can feed (BMS report feasibility)

**Directly feedable** (data present):
- **Revenue** — fait_facture_generique(+lignes): HT/TTC/TVA by day, client, category; `est_debours` separates pass-through disbursements from fee revenue.
- **Receivables** — reste_du, montant_encaisse per invoice + client payment terms (delai_paiement_jours) + collections (fait_paiement_client*).
- **Customer Insights** — dim_client × invoices/payments (concentration, aging, credit limits vs exposure, blocked clients).
- **Cash / Treasury (partial)** — fait_mouvement_caisse (till in/out) + avances de frais by caisse/bank.
- **Procurement** — fait_demande_achat → fait_bon_commande (lags, validation, supplier mix, rubrique budgétaire).
- **Operations (their core!)** — fait_dossier_transit + fait_etape_dossier: dossier lead times vs targets, step slippage, throughput, tonnage/containers by port/regime; fait_validation for approval-circuit bottlenecks.
- **Budget structure** — dim_rubrique_budgetaire exists, but no amounts table for budgets was exposed.

**NOT feedable — no source data exposed:**
- Trial Balance / GL Quality / OHADA statements / Balance Sheet (no GL, no chart of accounts)
- Inventory / product volumes (no stock or product tables)
- Payroll-ish HR metrics (dim_employe is a dimension only)
- Full tax compliance (only montant_tva on invoices; no declarations data)

**Data quality flags to verify with the provider:**
- fait_paiement_client has only ~5 rows vs 1,255 invoices — either collections data barely loaded, or payments live elsewhere. `montant_encaisse` on the invoice row looks like the reliable collection figure.
- fait_bon_commande / fait_demande_achat are tiny (45/49) — likely a recently started module.
