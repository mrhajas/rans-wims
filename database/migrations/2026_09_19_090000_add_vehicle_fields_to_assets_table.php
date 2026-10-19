<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purely additive columns for the Transport Routes module's Vehicle
 * Register, which reuses the existing Asset model/table (scoped to the
 * 'Vehicles' asset-category tree) instead of a new vehicles table — nearly
 * every other requested Vehicle field already exists on assets
 * (registration_number, vin_chassis_number, engine_number, manufacturer,
 * model, ownership_type, fuel_type, status, operating_personnel_contact_id
 * as "current driver"). Only year/color/capacity were missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('model');
            $table->string('color', 50)->nullable()->after('year');
            // Deliberately a free-text field, not decimal+unit — capacity
            // means different things for different vehicle types ("5 Tons",
            // "14 Seats", "20,000L") and forcing one unit would misrepresent
            // most of them.
            $table->string('capacity', 100)->nullable()->after('fuel_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['year', 'color', 'capacity']);
        });
    }
};
