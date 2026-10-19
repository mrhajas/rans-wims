<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Subcontracts: composite indexes for the sums every figure is built from (paid so far per agreement, active items per agreement). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subcontract_historical_payments')) {
            Schema::table('subcontract_historical_payments', fn (Blueprint $t) => $t->index(['agreement_id', 'counted', 'kind'], 'sc_hist_agr_counted_kind'));
        }
        if (Schema::hasTable('subcontract_work_items')) {
            Schema::table('subcontract_work_items', fn (Blueprint $t) => $t->index(['agreement_id', 'status'], 'sc_items_agr_status'));
        }
        if (Schema::hasTable('subcontract_payments')) {
            Schema::table('subcontract_payments', fn (Blueprint $t) => $t->index(['agreement_id', 'status'], 'sc_pay_agr_status'));
        }
    }

    public function down(): void
    {
        Schema::table('subcontract_historical_payments', fn (Blueprint $t) => $t->dropIndex('sc_hist_agr_counted_kind'));
        Schema::table('subcontract_work_items', fn (Blueprint $t) => $t->dropIndex('sc_items_agr_status'));
        Schema::table('subcontract_payments', fn (Blueprint $t) => $t->dropIndex('sc_pay_agr_status'));
    }
};
