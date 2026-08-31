<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users created by an admin receive a temporary password by email; this
 * flag forces them onto the change-password page on first sign-in and is
 * cleared the moment they save a password of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('must_change_password')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
        }
    }
};
