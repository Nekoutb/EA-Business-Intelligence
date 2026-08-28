<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse coverage for the models the Odoo sync never captured.
 *
 * Discovered while profiling Transatlantic (2026-08-28): the instance
 * holds 107,532 payments (16.77 Md XAF), 90,799 partial reconciliations,
 * 95,940 container products and a 25-journal structure that includes
 * per-client withholding-tax journals — none of which reached BMS. That
 * gap is why collections, DSO and cash application cannot be reported for
 * Odoo tenants.
 *
 * Index names are short and explicit on purpose: MySQL caps identifiers
 * at 64 chars and Laravel's generated names for these column tuples go
 * past it (that failure silently skipped a table creation once already).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Dimensions ──────────────────────────────────────────────
        if (!Schema::hasTable('wh_dim_product')) {
            Schema::create('wh_dim_product', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('name', 255)->nullable();
                $t->string('code', 64)->nullable();
                $t->string('category_name', 191)->nullable();
                $t->unsignedBigInteger('category_odoo_id')->nullable();
                $t->string('product_type', 32)->nullable();
                $t->decimal('list_price', 18, 4)->nullable();
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wdprod_tenant_odoo_uq');
                $t->index(['tenant_id', 'category_odoo_id'], 'wdprod_tenant_cat_ix');
                $t->index(['tenant_id', 'name'], 'wdprod_tenant_name_ix');
            });
        }

        if (!Schema::hasTable('wh_dim_journal')) {
            Schema::create('wh_dim_journal', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('name', 191)->nullable();
                $t->string('code', 32)->nullable();
                // bank | cash | sale | purchase | general
                $t->string('journal_type', 32)->nullable();
                // Derived at sync time from the journal name: Transatlantic
                // books retenue à la source in dedicated per-client journals,
                // which is the only way to isolate withholding tax.
                $t->boolean('is_withholding')->default(false);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wdjrn_tenant_odoo_uq');
                $t->index(['tenant_id', 'journal_type'], 'wdjrn_tenant_type_ix');
            });
        }

        // ── Facts ───────────────────────────────────────────────────
        if (!Schema::hasTable('wh_fact_payment')) {
            Schema::create('wh_fact_payment', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('name', 64)->nullable();
                $t->string('ref', 128)->nullable();
                $t->unsignedBigInteger('partner_odoo_id')->nullable();
                $t->unsignedBigInteger('journal_odoo_id')->nullable();
                // The journal entry this payment posted; joins to
                // wh_fact_invoice / wh_fact_gl_line on move_odoo_id.
                $t->unsignedBigInteger('move_odoo_id')->nullable();
                $t->string('payment_type', 16)->nullable();   // inbound | outbound
                $t->string('partner_type', 16)->nullable();   // customer | supplier
                $t->string('state', 32)->nullable();
                $t->date('payment_date')->nullable();
                $t->decimal('amount', 18, 2)->default(0);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wfpay_tenant_odoo_uq');
                $t->index(['tenant_id', 'payment_date'], 'wfpay_tenant_date_ix');
                $t->index(['tenant_id', 'partner_odoo_id'], 'wfpay_tenant_partner_ix');
                $t->index(['tenant_id', 'journal_odoo_id'], 'wfpay_tenant_jrn_ix');
                $t->index(['tenant_id', 'move_odoo_id'], 'wfpay_tenant_move_ix');
            });
        }

        // Cash application: which credit (payment) cleared which debit
        // (invoice). This is what turns "we received money" into "this
        // invoice is settled" — the basis for true DSO and AR ageing.
        if (!Schema::hasTable('wh_fact_reconcile')) {
            Schema::create('wh_fact_reconcile', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->unsignedBigInteger('debit_line_odoo_id')->nullable();
                $t->unsignedBigInteger('credit_line_odoo_id')->nullable();
                $t->unsignedBigInteger('debit_move_odoo_id')->nullable();
                $t->unsignedBigInteger('credit_move_odoo_id')->nullable();
                $t->decimal('amount', 18, 2)->default(0);
                $t->date('max_date')->nullable();
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id'], 'wfrec_tenant_odoo_uq');
                $t->index(['tenant_id', 'debit_move_odoo_id'], 'wfrec_tenant_dmove_ix');
                $t->index(['tenant_id', 'credit_move_odoo_id'], 'wfrec_tenant_cmove_ix');
                $t->index(['tenant_id', 'max_date'], 'wfrec_tenant_date_ix');
            });
        }

        // ── Column additions on existing facts ──────────────────────
        // voyage_type carries Transatlantic's custom Type_voyage field
        // (domestique / transit). Nullable: tenants without the field
        // simply leave it empty.
        if (Schema::hasTable('wh_fact_invoice') && !Schema::hasColumn('wh_fact_invoice', 'voyage_type')) {
            Schema::table('wh_fact_invoice', function (Blueprint $t) {
                $t->string('voyage_type', 32)->nullable()->after('payment_state');
                $t->unsignedBigInteger('journal_odoo_id')->nullable()->after('partner_odoo_id');
                $t->index(['tenant_id', 'voyage_type'], 'wfi_tenant_voyage_ix');
                $t->index(['tenant_id', 'journal_odoo_id'], 'wfi_tenant_jrn_ix');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_fact_reconcile');
        Schema::dropIfExists('wh_fact_payment');
        Schema::dropIfExists('wh_dim_journal');
        Schema::dropIfExists('wh_dim_product');
        // Column additions on shared tables are left in place on rollback.
    }
};
