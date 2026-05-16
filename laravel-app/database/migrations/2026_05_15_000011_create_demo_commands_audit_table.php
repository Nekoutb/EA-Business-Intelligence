<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every modification a demo visitor applies — whether
 * through the NL console, the canned forms, or direct data-table edits.
 *
 * Two uses:
 *   1. **Replay-able demo script.** The visitor can scroll back through
 *      what they tried and the BMS's reaction; sales engineers can copy
 *      the JSON to seed a similar demo for the next prospect.
 *   2. **Failure visibility.** When Claude misparses a command or the
 *      executor rejects an unbalanced journal, the error string lands
 *      here for the visitor to see + a screenshot lands in the demo
 *      session record we can review post-demo.
 *
 * `source`:
 *   nl_console   — typed into the natural-language box, Claude-parsed
 *   canned_form  — chosen from one of the quick-add modals
 *   direct_edit  — inline edit on a data table cell
 *   seed_reset   — bulk reset operation (status=success always)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_commands_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source', 24);                  // nl_console|canned_form|direct_edit|seed_reset
            $table->text('command_text')->nullable();      // raw user input (NL console)
            $table->json('parsed_operations')->nullable(); // structured ops Claude returned
            $table->string('status', 16);                  // success|failed
            $table->text('error_msg')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_commands_audit');
    }
};
