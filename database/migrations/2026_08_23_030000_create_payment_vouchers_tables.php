<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('pv_number')->unique();
            $table->foreignId('payee_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('payee_name');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->date('voucher_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('amount_in_words')->nullable();
            $table->string('status', 32)->default('Draft');

            $table->foreignId('prepared_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('authorised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();
            $table->text('approval_remarks')->nullable();

            $table->string('recipient_name')->nullable();
            $table->date('recipient_date')->nullable();
            $table->text('internal_notes')->nullable();

            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->nullOnDelete();

            $table->unsignedInteger('printed_count')->default(0);
            $table->timestamp('first_printed_at')->nullable();

            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('warehouse_id');
        });

        Schema::create('payment_voucher_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_voucher_id')->constrained('payment_vouchers')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('particulars');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_voucher_items');
        Schema::dropIfExists('payment_vouchers');
    }
};
