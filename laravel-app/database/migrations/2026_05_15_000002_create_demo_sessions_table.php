<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per visitor who entered the /demo/enter form.
 *
 * Serves three purposes:
 *   1. **Sales-lead capture.** Each entry records name + email + company
 *      so the team can follow up after the demo session.
 *   2. **Per-visitor sandbox isolation.** Each row maps a session ID to
 *      the freshly-spawned demo tenant; middleware looks up the tenant
 *      from the cookie on every demo request.
 *   3. **TTL + reaping.** expires_at lets the daily reaper kill the
 *      sandbox after the visitor leaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->id();
            // The Laravel session cookie value — we don't need a separate
            // token because the visitor already has a session cookie by
            // the time they hit /demo/start. Indexed for the resolver.
            $table->string('session_id', 64)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('company', 191)->nullable();
            $table->string('role', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['email']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sessions');
    }
};
