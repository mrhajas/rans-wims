<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Visitor Logs: each visit and each incoming item says which Project / Warehouse it was attended from ("Attended from"), so the lists and reports can be filtered by project. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['visitor_visits', 'visitor_incoming_items'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'warehouse_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('warehouse_id')->nullable()->index());
            }
        }
    }

    public function down(): void
    {
        foreach (['visitor_visits', 'visitor_incoming_items'] as $table) {
            if (Schema::hasColumn($table, 'warehouse_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('warehouse_id'));
            }
        }
    }
};
