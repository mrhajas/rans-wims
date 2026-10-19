<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            // 'invoice' = tied to specific supplier_invoices (see the pivot
            // table below). 'balance' = a general account-balance
            // settlement, applied FIFO across the supplier's outstanding
            // invoices only once the payment itself is recorded.
            $table->string('type', 16)->default('invoice');
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('date_requested');
            $table->decimal('amount', 15, 2);
            $table->string('status', 32)->default('Pending Approval');
            $table->text('approval_remarks')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('payment_request_supplier_invoice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['payment_request_id', 'supplier_invoice_id'], 'pr_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_supplier_invoice');
        Schema::dropIfExists('payment_requests');
    }
};
