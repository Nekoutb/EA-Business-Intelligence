<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining lifecycle dates the source keeps and the warehouse did not:
 * when billing was opened on a file and when it was suspended; when an
 * invoice left the office, was deposited with the client and its signed
 * receipt came back. All nullable — filled by the next ERP sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wh_fact_dossier') && !Schema::hasColumn('wh_fact_dossier', 'billing_start_date')) {
            Schema::table('wh_fact_dossier', function (Blueprint $t) {
                $t->date('billing_start_date')->nullable()->after('closed_date');
                $t->date('suspended_date')->nullable()->after('billing_start_date');
            });
        }
        if (Schema::hasTable('wh_fact_invoice') && !Schema::hasColumn('wh_fact_invoice', 'deposited_date')) {
            Schema::table('wh_fact_invoice', function (Blueprint $t) {
                $t->date('sent_date')->nullable()->after('invoice_date_due');
                $t->date('deposited_date')->nullable()->after('sent_date');
                $t->date('receipt_date')->nullable()->after('deposited_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wh_fact_dossier') && Schema::hasColumn('wh_fact_dossier', 'billing_start_date')) {
            Schema::table('wh_fact_dossier', fn (Blueprint $t) => $t->dropColumn(['billing_start_date', 'suspended_date']));
        }
        if (Schema::hasTable('wh_fact_invoice') && Schema::hasColumn('wh_fact_invoice', 'deposited_date')) {
            Schema::table('wh_fact_invoice', fn (Blueprint $t) => $t->dropColumn(['sent_date', 'deposited_date', 'receipt_date']));
        }
    }
};
