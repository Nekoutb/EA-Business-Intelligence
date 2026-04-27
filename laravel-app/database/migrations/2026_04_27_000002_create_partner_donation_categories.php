<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant cache of donation-recipient category resolution.
 *
 * Spec asked for the resolved category to live on
 * res.partner.x_donation_category_cm (Odoo custom field). To keep the
 * feature working on any Odoo install — including those without the
 * custom field defined — we store it locally as the source of truth
 * and best-effort write to Odoo on save.
 *
 * One row per (tenant_id, odoo_partner_id). Includes the supporting-doc
 * metadata (type, reference, uploaded file path) and the non-resident
 * flag used to apply the additional 15% TSR (Article 225 CGI) on
 * Catégorie 3 donations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_donation_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('odoo_partner_id');
            $table->string('partner_name', 191)->nullable();
            $table->string('partner_niu', 64)->nullable();          // NIU / VAT id, cached from Odoo
            $table->unsignedTinyInteger('category')->nullable();     // 1, 2, 3 — null = unresolved
            $table->boolean('is_non_resident')->default(false);
            $table->string('supporting_doc_type', 64)->nullable();   // e.g. "Statuts", "Attestation utilité publique"
            $table->string('supporting_doc_reference', 191)->nullable();
            $table->string('supporting_doc_path', 255)->nullable();  // Laravel storage path
            $table->text('notes')->nullable();
            $table->timestamp('odoo_synced_at')->nullable();         // last successful Odoo write-back
            $table->text('odoo_sync_error')->nullable();             // non-empty if last sync failed
            $table->timestamps();

            $table->unique(['tenant_id', 'odoo_partner_id']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_donation_categories');
    }
};
