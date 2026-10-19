<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purely additive — every existing row gets workforce_agreement_id=null,
     * approved_amount=null, so `approved_amount ?? amount` resolves to
     * exactly today's `amount` for every in-flight/historical Labour List.
     */
    public function up(): void
    {
        Schema::table('labour_list_items', function (Blueprint $table) {
            $table->foreignId('workforce_agreement_id')->nullable()->after('labour_list_id')->constrained('workforce_agreements')->nullOnDelete();
            $table->decimal('approved_amount', 15, 2)->nullable()->after('amount');
            $table->text('revision_reason')->nullable()->after('approved_amount');
        });
    }

    public function down(): void
    {
        Schema::table('labour_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workforce_agreement_id');
            $table->dropColumn(['approved_amount', 'revision_reason']);
        });
    }
};
