<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GuardsUniqueOpeningBalance's check-then-create in the FormRequest is a
 * best-effort guard only — with no existing row to lockForUpdate() the very
 * first time a worker/project pairing gets an opening balance, two
 * concurrent submissions can both pass the "none exists yet" check before
 * either commits. A generated column collapses "warehouse + worker" down to
 * one comparable value only when opening_balance is actually set on an
 * Active agreement (NULL otherwise, and MySQL/MariaDB unique indexes allow
 * unlimited NULLs) — turning the rule into a real, race-proof DB constraint
 * instead of a read-then-write check. Recomputed automatically by the
 * server on every insert/update, so cancelling an agreement correctly frees
 * its slot for a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE workforce_agreements
            ADD COLUMN opening_balance_lock_key VARCHAR(100)
            GENERATED ALWAYS AS (
                CASE WHEN opening_balance > 0 AND status = 'Active'
                     THEN CONCAT(warehouse_id, '-', IFNULL(contact_id, 0), '-', IFNULL(staff_id, 0))
                     ELSE NULL
                END
            ) STORED
        ");

        Schema::table('workforce_agreements', function ($table) {
            $table->unique('opening_balance_lock_key', 'workforce_agreements_opening_balance_lock_unique');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_agreements', function ($table) {
            $table->dropUnique('workforce_agreements_opening_balance_lock_unique');
            $table->dropColumn('opening_balance_lock_key');
        });
    }
};
