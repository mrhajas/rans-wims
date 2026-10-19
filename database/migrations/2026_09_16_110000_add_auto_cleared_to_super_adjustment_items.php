<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes a line the user actually counted from one auto-added at
     * zero via the "Clear" action on the uncounted-items warning (items
     * still stocked in the warehouse but left out of the physical count) —
     * see SuperAdjustmentController::persist()/uncountedItems() and
     * Form.vue's resolveUncounted(). Purely informational: it does not
     * change how variance/approval math treats the row.
     */
    public function up(): void
    {
        Schema::table('super_adjustment_items', function (Blueprint $table) {
            $table->boolean('auto_cleared')->default(false)->after('physical_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('super_adjustment_items', function (Blueprint $table) {
            $table->dropColumn('auto_cleared');
        });
    }
};
