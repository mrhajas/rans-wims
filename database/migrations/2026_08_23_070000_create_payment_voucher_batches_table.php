<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk Payment Voucher Creation: a batch is only a grouping/creation
 * convenience — each row it creates is a fully independent payment_vouchers
 * record (own pv_number, own approval/print/audit trail). Nothing about
 * approval, permissions, or printing is duplicated here; payment_vouchers
 * just grows an optional back-reference to the batch that spawned it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_voucher_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique();
            $table->string('type', 100);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->date('voucher_date');
            $table->string('common_particulars');
            $table->string('reference')->nullable();
            $table->text('common_notes')->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->foreignId('payment_voucher_batch_id')->nullable()->after('pv_number')
                ->constrained('payment_voucher_batches')->nullOnDelete();
            $table->index('payment_voucher_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_voucher_batch_id');
        });

        Schema::dropIfExists('payment_voucher_batches');
    }
};
