<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual debit/credit lines on a demo journal entry.
 *
 * Every line carries exactly one of `debit` or `credit` non-zero; the
 * other is 0. The DemoCommandExecutor validates that the sum of debits
 * equals the sum of credits before persisting, so the visitor cannot
 * book an unbalanced journal entry from the console.
 *
 * `account_code` is the canonical accounting code (e.g. "6111", "4011",
 * "70110"). The corresponding `account_name` is denormalised here for
 * fast joinless reporting in the demo P&L and Trial Balance views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('demo_journal_entries')->cascadeOnDelete();
            $table->string('account_code', 16);
            $table->string('account_name', 191);
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('partner', 191)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index('account_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_journal_lines');
    }
};
