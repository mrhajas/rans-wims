<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle Opening Balance — a one-time, historic outstanding amount a
 * vehicle already carried before it was registered in Transport Routes.
 * Columns live directly on `assets` (not a new table) since it's a single
 * current-state value per vehicle, not a ledger of entries. The "set once,
 * correction needs password confirmation" rule lives entirely in
 * TransportVehicleController — these columns just hold whatever the latest
 * approved value is, with every change additionally recorded via
 * log_activity() (readable on the Vehicle Show page's Activity History).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->nullable()->after('current_odometer');
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
            $table->text('opening_balance_notes')->nullable()->after('opening_balance_date');
            $table->foreignId('opening_balance_set_by_user_id')->nullable()->after('opening_balance_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('opening_balance_set_at')->nullable()->after('opening_balance_set_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_set_by_user_id');
            $table->dropColumn(['opening_balance', 'opening_balance_date', 'opening_balance_notes', 'opening_balance_set_at']);
        });
    }
};
