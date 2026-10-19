<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original unique(['name', 'location_type']) index doesn't know about
 * deleted_at, so soft-deleting a location (TransportLocationController::destroy())
 * permanently blocked ever recreating one with the same name — the DB
 * rejected the insert even though TransportLocationRequest's own validation
 * had just been fixed with withoutTrashed() to allow it. Dropping the DB
 * index and relying on that application-level check only matches the same
 * choice already made for Asset::registration_number (see
 * TransportVehicleRequest's own comment on that field): a blanket index that
 * can't distinguish "soft-deleted" from "live" is worse than no index here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_locations', function (Blueprint $table) {
            $table->dropUnique(['name', 'location_type']);
        });
    }

    public function down(): void
    {
        Schema::table('transport_locations', function (Blueprint $table) {
            $table->unique(['name', 'location_type']);
        });
    }
};
