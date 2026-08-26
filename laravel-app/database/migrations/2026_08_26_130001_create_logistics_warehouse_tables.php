<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics Operations warehouse (Elimelec sector, 2026-08).
 *
 * Facts for the dossier-based logistics pages, synced from the tenant's
 * external ERP database by ErpDbSyncService. Dimension labels are
 * denormalized onto the facts (type/port/supplier names) so no extra dim
 * tables are needed. All keys are wh_key_map surrogates; explicit short
 * index names — MySQL's 64-char identifier limit bit us once already.
 *
 * Also links the existing invoice facts to dossiers (dossier_odoo_id on
 * wh_fact_invoice) and tags lines with their fee/débours nature —
 * the profitability and unit-economics sections read those.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wh_fact_dossier')) {
            Schema::create('wh_fact_dossier', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('code', 64)->nullable();
                $t->unsignedBigInteger('partner_odoo_id')->nullable();
                $t->string('client_name', 191)->nullable();
                $t->string('type_name', 128)->nullable();
                $t->string('port_name', 128)->nullable();
                $t->string('employee_name', 128)->nullable();
                $t->date('opened_date')->nullable();
                $t->date('closed_date')->nullable();
                $t->boolean('is_closed')->default(false);
                $t->boolean('is_suspended')->default(false);
                $t->decimal('lead_days', 10, 2)->nullable();
                $t->decimal('billing_lag_days', 10, 2)->nullable();
                $t->decimal('weight_kg', 18, 3)->nullable();
                $t->integer('containers')->nullable();
                $t->integer('packages')->nullable();
                $t->decimal('cargo_value', 18, 2)->nullable();
                $t->string('regime', 64)->nullable();
                $t->string('incoterm', 32)->nullable();
                $t->string('embarquement', 64)->nullable();
                $t->string('importer', 191)->nullable();
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wfd_tenant_odoo_uq');
                $t->index(['tenant_id', 'opened_date'], 'wfd_tenant_open_ix');
                $t->index(['tenant_id', 'is_closed'], 'wfd_tenant_closed_ix');
            });
        }

        if (!Schema::hasTable('wh_fact_advance')) {
            Schema::create('wh_fact_advance', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->unsignedBigInteger('dossier_odoo_id')->nullable();
                $t->string('supplier_name', 191)->nullable();
                $t->string('label', 96)->nullable();
                $t->decimal('amount', 18, 2)->default(0);
                $t->date('requested_date')->nullable();
                $t->date('disbursed_date')->nullable();
                $t->decimal('disbursement_lag_days', 10, 2)->nullable();
                $t->boolean('is_justified')->default(false);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wfa_tenant_odoo_uq');
                $t->index(['tenant_id', 'dossier_odoo_id'], 'wfa_tenant_dossier_ix');
                $t->index(['tenant_id', 'disbursed_date'], 'wfa_tenant_date_ix');
            });
        }

        if (!Schema::hasTable('wh_fact_validation')) {
            Schema::create('wh_fact_validation', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('doc_key', 96)->nullable();
                $t->string('doc_type', 96)->nullable();
                $t->string('circuit', 96)->nullable();
                $t->integer('rang')->nullable();
                $t->date('doc_date')->nullable();
                $t->date('step_date')->nullable();
                $t->decimal('step_lag_days', 10, 3)->nullable();
                $t->decimal('total_days', 10, 3)->nullable();
                $t->boolean('is_passed')->default(false);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wfv_tenant_odoo_uq');
                $t->index(['tenant_id', 'doc_date'], 'wfv_tenant_date_ix');
            });
        }

        if (!Schema::hasColumn('wh_fact_invoice', 'dossier_odoo_id')) {
            Schema::table('wh_fact_invoice', function (Blueprint $t) {
                $t->unsignedBigInteger('dossier_odoo_id')->nullable()->after('partner_odoo_id');
                $t->index(['tenant_id', 'dossier_odoo_id'], 'wfi_tenant_dossier_ix');
            });
        }

        if (!Schema::hasColumn('wh_fact_invoice_line', 'is_debours')) {
            Schema::table('wh_fact_invoice_line', function (Blueprint $t) {
                $t->boolean('is_debours')->nullable()->after('price_subtotal');
                $t->string('label', 96)->nullable()->after('is_debours');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_fact_validation');
        Schema::dropIfExists('wh_fact_advance');
        Schema::dropIfExists('wh_fact_dossier');
        // Column adds on shared tables are left in place on rollback.
    }
};
