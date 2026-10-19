<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Subcontracts: who submitted a payment request or a variation (the maker-checker rule needs the submitter as well as the creator). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['subcontract_payment_requests', 'subcontract_variations'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'submitted_by_user_id')) {
                Schema::table($t, function (Blueprint $b) {
                    $b->unsignedBigInteger('submitted_by_user_id')->nullable();
                    $b->timestamp('submitted_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['subcontract_payment_requests', 'subcontract_variations'] as $t) {
            if (Schema::hasColumn($t, 'submitted_by_user_id')) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn(['submitted_by_user_id', 'submitted_at']));
            }
        }
    }
};
