<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transport_records only had trip_date indexed as part of the
 * (asset_id, trip_date) / (driver_contact_id, trip_date) composites — any
 * query filtering by trip_date alone (Dashboard's trips_today/trips_this_month,
 * and Reports/scopeFilter's date_from/date_to with no vehicle/driver picked,
 * the common case) couldn't use either composite and fell back to a full
 * table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->index('trip_date');
        });
    }

    public function down(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->dropIndex(['trip_date']);
        });
    }
};
