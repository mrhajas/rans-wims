<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SupplierLedgerEntry already declares $tracksDeletes = true (Model::boot()
 * records who deleted a row via `deleted_by`), but the table was created
 * without that column since nothing deleted these rows yet. Needed now that
 * Super Admins can delete an Opening Balance entry (with password
 * confirmation) from the Ledger page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->after('verified_on')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
