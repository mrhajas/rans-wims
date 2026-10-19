<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The shared base model soft-deletes, so these two tables need the column too. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fund_allocation_item_lines', 'fund_allocation_balances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['fund_allocation_item_lines', 'fund_allocation_balances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
