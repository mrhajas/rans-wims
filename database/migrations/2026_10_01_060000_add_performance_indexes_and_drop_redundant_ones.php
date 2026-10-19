<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From a performance audit: activity_log (the biggest, fastest-growing
 * table) and stock_trails are range-scanned by created_at on every
 * dashboard load with no supporting index; checkins/checkouts/transfers
 * are range-scanned by date the same way for reports/dashboard charts.
 * Also drops three single-column indexes made redundant by an existing
 * composite index that already covers the same leftmost column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('stock_trails', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('checkins', function (Blueprint $table) {
            $table->index('date');
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->index('date');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->index('date');
        });

        Schema::table('checkin_items', function (Blueprint $table) {
            $table->dropIndex('checkin_items_checkin_id_index');
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            $table->dropIndex('transfer_items_transfer_id_index');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropIndex('stocks_item_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('stock_trails', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('checkins', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });

        Schema::table('checkin_items', function (Blueprint $table) {
            $table->index('checkin_id', 'checkin_items_checkin_id_index');
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            $table->index('transfer_id', 'transfer_items_transfer_id_index');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->index('item_id', 'stocks_item_id_index');
        });
    }
};
