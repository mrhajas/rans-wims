<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "Mixed Main Warehouses": one trip departing from / delivering to several Main Warehouses. The chosen warehouses
    // are kept as a list of ids; the single warehouse_id / departure_warehouse_id stay empty for such a trip.
    public function up(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->json('departure_warehouse_ids')->nullable()->after('departure_warehouse_id');
            $table->json('destination_warehouse_ids')->nullable()->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->dropColumn(['departure_warehouse_ids', 'destination_warehouse_ids']);
        });
    }
};
