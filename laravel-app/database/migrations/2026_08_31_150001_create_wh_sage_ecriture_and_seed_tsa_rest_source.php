<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landing table for Sage GL entries pulled through a REST gateway
 * (ERP_TYPE=rest — see SageRestSyncService), and the second-source
 * credentials for Transatlantic (tsa): their Sage 100 accounting behind
 * https://apisage.t-a-cam.com (read-only key supplied by the customer).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wh_sage_ecriture')) {
            Schema::create('wh_sage_ecriture', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedTinyInteger('source')->default(1);
                $t->date('ecr_date')->nullable();
                $t->string('journal', 32)->default('');
                $t->string('account', 32)->default('');
                $t->string('partner', 64)->nullable();
                $t->string('piece', 64)->default('');
                $t->string('ref_piece', 64)->nullable();
                $t->string('label', 191)->default('');
                $t->unsignedTinyInteger('sens')->default(0); // Sage EC_Sens: 0=débit, 1=crédit
                $t->decimal('amount', 18, 2)->default(0);
                $t->string('lettrage', 16)->nullable();
                $t->index(['tenant_id', 'source', 'ecr_date']);
                $t->index(['tenant_id', 'account']);
            });
        }

        $tenantId = DB::table('tenants')->where('slug', 'tsa')->value('id');
        if ($tenantId) {
            $set = [
                'ERP2_TYPE'           => 'rest',
                'ERP2_NAME'           => 'Sage 100 (comptabilité)',
                'ERP2_API_URL'        => 'https://apisage.t-a-cam.com',
                'ERP2_API_KEY_HEADER' => 'X-API-Key',
                'ERP2_API_KEY'        => 'hBZ4fY1Z19htvEMB0s8fOmUXtd56htpFPQFYt21xmZk=',
                'ERP2_API_DATABASE'   => 'TSA COMPTA 2026 SQL',
            ];
            foreach ($set as $key => $value) {
                DB::table('tenant_settings')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'key' => $key],
                    ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_sage_ecriture');
    }
};
