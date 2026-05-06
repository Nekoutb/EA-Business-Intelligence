<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line override of donation-recipient category resolution.
 *
 * Companion to partner_donation_categories — the partner table holds the
 * default category for each beneficiary, this table holds *line-level
 * overrides* the auditor sets when a specific posting needs to land in a
 * different bucket than the partner default would suggest. Common cases:
 *
 *   • Same beneficiary received money under two rationales (Cat 1 grant
 *     + Cat 2 sponsorship) so partner default is wrong for one of them
 *   • Line is misposted to a partner whose default doesn't apply here
 *   • Line has no partner at all (cash gift booked directly)
 *
 * Resolution precedence at calc time (per Article 7 CGI deductibility):
 *   line override (if set)  →  partner default (if set)  →  Cat 3 fallback
 *
 * Cat 3 is the conservative fallback (non-deductible) — auditor intent
 * is "if I haven't told you otherwise, treat it as non-deductible until
 * I prove otherwise." UI surfaces a banner counting how many lines are
 * resolving via Cat 3 fallback so the auditor knows what's outstanding.
 *
 * Denormalised columns (account_code, line_date, amount, partner_id)
 * are intentional — they let us audit-trail the line state at
 * assignment time without round-tripping Odoo every time we render the
 * categorisation table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_donation_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('odoo_move_line_id');             // account.move.line id
            $table->unsignedInteger('odoo_move_id');                  // account.move id (for grouping)
            $table->unsignedInteger('odoo_partner_id')->nullable();   // copy at assign-time
            $table->string('account_code', 16);                       // e.g. '658200' / '658300'
            $table->date('line_date');                                // copy at assign-time
            $table->decimal('amount', 18, 2);                         // copy at assign-time (XAF, no fractions in practice)
            $table->unsignedTinyInteger('category');                  // 1 | 2 | 3 — required (NULL = no override)
            $table->text('note')->nullable();                         // free-form rationale (e.g. "Reconnu utilité publique 2024-12-08")
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'odoo_move_line_id']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'odoo_move_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_donation_categories');
    }
};
