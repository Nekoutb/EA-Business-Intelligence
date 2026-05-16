<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entry header for demo sandboxes — the OHADA-style
 * double-entry move that the visitor can directly book from the NL
 * console ("Debit account 6111 100,000 / Credit account 4011 100,000").
 *
 * `journal_code` is the OHADA journal abbreviation:
 *   VTE = Sales
 *   ACH = Purchases
 *   BNK = Bank
 *   CSH = Cash
 *   OD  = Operations diverses (general)
 *
 * The Profit & Loss + Balance Sheet review modules sum demo_journal_lines
 * by account class to produce their figures, so anything the visitor
 * books here flows straight through to the headline KPIs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('journal_code', 8)->default('OD');
            $table->string('reference', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('posted')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'entry_date']);
            $table->index(['tenant_id', 'journal_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_journal_entries');
    }
};
