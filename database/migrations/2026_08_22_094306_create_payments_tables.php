<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('payment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method', 32);
            $table->string('currency', 10)->default('TZS');
            $table->string('cheque_number', 60)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->date('paid_on');
            $table->foreignId('paid_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            // Directors (Super Admin) sign off after the fact — every
            // payment starts Pending Verification.
            $table->string('verification_status', 32)->default('Pending Verification');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_on')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id']);
            $table->index(['verification_status']);
        });

        // Exactly how much of a payment applied to each invoice — a normal
        // invoice-based payment has one row; a balance payment can span
        // several invoices via FIFO allocation (see Payment::allocate()).
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
