<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purely additive source-document links, following the exact same
     * pattern payment_vouchers already uses for purchase_order_id /
     * supplier_invoice_id / payment_request_id — plain nullable FKs, no
     * morph relation, zero risk to any existing row or query.
     */
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->foreignId('labour_list_id')->nullable()->after('payment_request_id')->constrained('labour_lists')->nullOnDelete();
            $table->foreignId('labour_list_item_id')->nullable()->after('labour_list_id')->constrained('labour_list_items')->nullOnDelete();
        });

        Schema::table('payment_voucher_batches', function (Blueprint $table) {
            $table->foreignId('labour_list_id')->nullable()->after('reference')->constrained('labour_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labour_list_item_id');
            $table->dropConstrainedForeignId('labour_list_id');
        });

        Schema::table('payment_voucher_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labour_list_id');
        });
    }
};
