<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one Payment Voucher line item point back at the Transport Record it
 * pays for. Combined with transport_records.payment_voucher_id (the FK for
 * "which voucher paid this trip"), this is the entire change needed on the
 * PaymentVoucher side — deliberately not a singular FK on payment_vouchers
 * itself, since requirement 9 explicitly wants many trips combinable into
 * one voucher (one voucher -> many transport_records, matching the existing
 * one voucher -> many payment_voucher_items shape already in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_voucher_items', function (Blueprint $table) {
            $table->foreignId('transport_record_id')->nullable()->after('payment_voucher_id')
                ->constrained('transport_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_voucher_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transport_record_id');
        });
    }
};
