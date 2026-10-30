<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External Stock: the warehouse is chosen for every item, not once for the whole movement. Existing lines take the warehouse of their movement,
 * so every balance stays exactly as it was. The movement keeps its own warehouse_id as the first line's warehouse (lists, alerts and documents use it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_stock_items', 'warehouse_id')) {
            Schema::table('external_stock_items', function (Blueprint $t) {
                $t->unsignedBigInteger('warehouse_id')->nullable()->after('item_id')->index();
            });
        }
        DB::statement('UPDATE external_stock_items i JOIN external_stock_movements m ON m.id = i.movement_id SET i.warehouse_id = m.warehouse_id WHERE i.warehouse_id IS NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_stock_items', 'warehouse_id')) {
            Schema::table('external_stock_items', fn (Blueprint $t) => $t->dropColumn('warehouse_id'));
        }
    }
};
