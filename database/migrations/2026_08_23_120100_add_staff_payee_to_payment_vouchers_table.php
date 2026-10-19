<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a voucher's payee be a RANS Staff member instead of a Contact —
 * `payee_name` stays the single source of truth for display/print/export
 * regardless of which type is used, so nothing downstream needs to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->string('payee_type', 32)->default('External Personnel')->after('pv_number');
            $table->foreignId('staff_id')->nullable()->after('payee_contact_id')->constrained('staffs')->nullOnDelete();
        });

        Schema::table('payment_voucher_batches', function (Blueprint $table) {
            $table->string('payee_type', 32)->default('External Personnel')->after('batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_id');
            $table->dropColumn('payee_type');
        });

        Schema::table('payment_voucher_batches', function (Blueprint $table) {
            $table->dropColumn('payee_type');
        });
    }
};
