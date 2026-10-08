<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password-reset tokens keyed by USER, not email: the same email can own
 * separate accounts in several workspaces (and the platform console), so
 * Laravel's stock email-keyed password_reset_tokens table cannot tell
 * them apart. Only a SHA-256 of the token is stored; the raw token lives
 * solely in the emailed link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_password_resets')) {
            Schema::create('user_password_resets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->char('token_hash', 64)->unique();
                $t->string('requested_by', 16)->default('self'); // self | admin
                $t->string('requested_ip', 45)->nullable();
                $t->timestamp('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_password_resets');
    }
};
