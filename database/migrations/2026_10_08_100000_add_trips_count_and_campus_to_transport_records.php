<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One record can now cover several identical trips (1 to 50): `unit_amount` is the price of one trip, `trips_count`
 * how many were made, and `amount` stays the TOTAL (unit x trips), so every balance, voucher and report that sums
 * `amount` keeps working unchanged. `is_campus` marks a movement inside one big site (departure and destination are
 * the same site, so no separate destination is chosen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('trips_count')->default(1)->after('default_amount');
            $table->decimal('unit_amount', 15, 2)->nullable()->after('trips_count');
            $table->boolean('is_campus')->default(false)->after('destination_label');
        });

        DB::table('transport_records')->whereNull('unit_amount')->update(['unit_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->dropColumn(['trips_count', 'unit_amount', 'is_campus']);
        });
    }
};
