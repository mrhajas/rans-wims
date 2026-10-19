<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers that leave a MAIN warehouse need approval before stock moves. A submitted transfer sits in
 * `pending` (draft=1, so no stock effect) until someone with approve-transfers approves it, or is `cancelled`.
 * Everything else keeps `not_required`. Transfers from a main warehouse that already moved stock before this
 * rule existed are marked `approved` (approver unknown) so the list shows them as settled, not pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('not_required')->after('draft')->index();
            $table->foreignId('cancelled_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
        });

        DB::table('transfers')
            ->join('warehouses', 'warehouses.id', '=', 'transfers.from_warehouse_id')
            ->whereIn(DB::raw('LOWER(TRIM(warehouses.status))'), ['main warehouse', 'main warehouses'])
            ->where('transfers.draft', 0)
            ->update(['transfers.approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'cancelled_by', 'cancelled_at', 'cancel_reason']);
        });
    }
};
