<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PaymentVoucher's status machine deliberately stops at "Authorised" —
     * these columns add a lightweight, optional "payment completed" record
     * layered on top of Authorised without touching the core status enum or
     * any of the existing status-based conditionals across the module.
     */
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('authorised_at');
            $table->string('payment_reference')->nullable()->after('paid_at');
            $table->string('payment_method')->nullable()->after('payment_reference');
            $table->foreignId('paid_by_user_id')->nullable()->after('payment_method')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropColumn(['paid_at', 'payment_reference', 'payment_method']);
        });
    }
};
