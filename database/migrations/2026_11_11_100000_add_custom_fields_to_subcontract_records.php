<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Subcontracts: user-defined fields. Their values live in `extra_attributes` (JSON, under the key "custom") of the record they belong to. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['subcontract_work_items', 'subcontract_variations', 'subcontract_payment_requests'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'extra_attributes')) {
                Schema::table($t, fn (Blueprint $b) => $b->longText('extra_attributes')->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['subcontract_work_items', 'subcontract_variations', 'subcontract_payment_requests'] as $t) {
            if (Schema::hasColumn($t, 'extra_attributes')) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn('extra_attributes'));
            }
        }
    }
};
