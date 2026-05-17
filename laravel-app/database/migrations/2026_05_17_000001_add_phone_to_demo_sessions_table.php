<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add phone column to demo_sessions for richer lead capture.
 *
 * Nullable so existing rows + visitors who skip the field still work.
 * Indexed for sales-team lookups by phone (rare but useful when a lead
 * follows up by phone and the team needs to match them back to a
 * demo session).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn('phone');
        });
    }
};
