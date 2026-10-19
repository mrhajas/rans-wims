<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL (unlike MariaDB, which never enforced this) rejects ON DELETE
     * CASCADE / SET NULL on any column that feeds the STORED generated
     * `opening_balance_lock_key` (warehouse_id, contact_id, staff_id) —
     * error 3192, "Cannot add foreign key on the base column of stored
     * column". The original create-table migration predates the generated
     * column (added later, in add_opening_balance_uniqueness_guard...), so
     * it never anticipated the conflict. Swapping these three to RESTRICT
     * (Laravel's default when no ...OnDelete() is specified) is the
     * MySQL-compatible fix — it only changes behavior on a hard/forced
     * delete of a warehouse, contact, or staff record that still has a
     * workforce agreement attached (soft deletes, the normal path, never
     * reach the DB-level ON DELETE action at all), and arguably makes that
     * edge case safer: you're stopped and told to deal with the agreement
     * first, instead of it silently vanishing or losing its link.
     */
    public function up(): void
    {
        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['staff_id']);
        });

        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('contact_id')->references('id')->on('contacts');
            $table->foreign('staff_id')->references('id')->on('staffs');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['staff_id']);
        });

        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('staff_id')->references('id')->on('staffs')->nullOnDelete();
        });
    }
};
