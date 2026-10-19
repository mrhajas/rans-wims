<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loading & Unloading personnel can be paid through a Payment Voucher too. The payment row then waits as
 * "Pending Voucher" (it reserves the amount but does not reduce the balance yet) and becomes "Paid" when the voucher
 * is recorded as paid; a rejected / cancelled / deleted voucher releases it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_loading_payments', function (Blueprint $table) {
            $table->string('status', 20)->default('Paid')->after('amount'); // Paid | Pending Voucher
            $table->foreignId('payment_voucher_id')->nullable()->after('status')->constrained('payment_vouchers')->nullOnDelete();
            $table->index('status');
        });

        Schema::table('payment_voucher_items', function (Blueprint $table) {
            $table->unsignedBigInteger('transport_loading_payment_id')->nullable()->after('transport_record_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_voucher_items', function (Blueprint $table) {
            $table->dropColumn('transport_loading_payment_id');
        });
        Schema::table('transport_loading_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_voucher_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'payment_voucher_id']);
        });
    }
};
