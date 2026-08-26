<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surrogate-key map for external (non-Odoo) ERP sources.
 *
 * The warehouse tables key every row on an integer odoo_id, but external
 * ERPs expose string keys (e.g. Elimelec's cle_client / cle_facture).
 * This table hands out a stable integer per (tenant, entity, external key)
 * — the auto-increment id IS the surrogate — so external rows can occupy
 * the same warehouse tables without schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wh_key_map')) {
            Schema::create('wh_key_map', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('entity', 32);
                $t->string('ext_key', 191);
                $t->timestamps();
                $t->unique(['tenant_id', 'entity', 'ext_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_key_map');
    }
};
