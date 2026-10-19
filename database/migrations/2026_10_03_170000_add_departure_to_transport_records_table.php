<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departure mirrors Destination exactly: a type (Project, Warehouse, Supplier, Client, Office…) plus the
 * matching record — warehouse, contact or transport location — and a label frozen at save time.
 * The existing free-text departure_location stays as the optional "address / gate" detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->string('departure_type', 32)->nullable()->after('driver_contact_id');
            $table->foreignId('departure_warehouse_id')->nullable()->after('departure_type')->constrained('warehouses')->nullOnDelete();
            $table->foreignId('departure_contact_id')->nullable()->after('departure_warehouse_id')->constrained('contacts')->nullOnDelete();
            $table->foreignId('departure_transport_location_id')->nullable()->after('departure_contact_id')->constrained('transport_locations')->nullOnDelete();
            $table->string('departure_label')->nullable()->after('departure_transport_location_id');
            $table->index('departure_type');
        });
    }

    public function down(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->dropForeign(['departure_warehouse_id']);
            $table->dropForeign(['departure_contact_id']);
            $table->dropForeign(['departure_transport_location_id']);
            $table->dropIndex(['departure_type']);
            $table->dropColumn(['departure_type', 'departure_warehouse_id', 'departure_contact_id', 'departure_transport_location_id', 'departure_label']);
        });
    }
};
