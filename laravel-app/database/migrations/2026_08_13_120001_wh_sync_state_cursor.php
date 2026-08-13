<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the sync a keyset cursor instead of a write_date-only watermark.
 *
 * A watermark of write_date alone cannot resume precisely when many records
 * share one write_date: the resume domain has to be '>=' to avoid skipping
 * the rest of that group, so every run re-reads the group from its start.
 * Once a same-write_date group is larger than one run's time budget, the
 * sync re-reads the same slice forever and never advances.
 *
 * last_id makes the cursor unique — (write_date, id) is strictly ordered,
 * so a run resumes exactly after the last row it read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wh_sync_state')) return;
        if (Schema::hasColumn('wh_sync_state', 'last_id')) return;

        Schema::table('wh_sync_state', function (Blueprint $t) {
            $t->unsignedBigInteger('last_id')->nullable()->after('last_write_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('wh_sync_state')) return;
        if (!Schema::hasColumn('wh_sync_state', 'last_id')) return;

        Schema::table('wh_sync_state', function (Blueprint $t) {
            $t->dropColumn('last_id');
        });
    }
};
