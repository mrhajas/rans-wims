<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'location')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('location', 150)->nullable()->after('phone');
            });
        }

        // The person's location at the time of the movement (copied from the contact, so old records keep what was true then).
        if (! Schema::hasColumn('external_stock_movements', 'location')) {
            Schema::table('external_stock_movements', function (Blueprint $table) {
                $table->string('location', 150)->nullable()->after('contact_id');
            });
        }

        // Stock Out may leave the project(s) / warehouse(s) open, so the warehouse can be empty.
        Schema::table('external_stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('external_stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('external_stock_movements', 'location')) {
                $table->dropColumn('location');
            }
        });
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'location')) {
                $table->dropColumn('location');
            }
        });
    }
};
