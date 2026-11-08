<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data hosting (multi-server): where each client's data layer lives.
 * Every existing client stays 'shared' (platform database), so this
 * migration changes nothing in behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'data_location')) $t->string('data_location', 16)->default('shared');
            if (!Schema::hasColumn('tenants', 'db_config')) $t->text('db_config')->nullable();        // encrypted JSON
            if (!Schema::hasColumn('tenants', 'db_schema_version')) $t->string('db_schema_version', 64)->nullable();
            if (!Schema::hasColumn('tenants', 'db_status')) $t->string('db_status', 16)->nullable();   // ok | down | behind
            if (!Schema::hasColumn('tenants', 'db_checked_at')) $t->timestamp('db_checked_at')->nullable();
            if (!Schema::hasColumn('tenants', 'db_latency_ms')) $t->unsignedInteger('db_latency_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            foreach (['data_location', 'db_config', 'db_schema_version', 'db_status', 'db_checked_at', 'db_latency_ms'] as $c) {
                if (Schema::hasColumn('tenants', $c)) $t->dropColumn($c);
            }
        });
    }
};
