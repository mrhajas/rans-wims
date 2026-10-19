<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_lists', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 32)->default('Draft');
            $table->text('description')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Reused across Return/Reject at either stage — overwritten each
            // cycle, exactly like PaymentVoucher's single approval_remarks
            // column; full history lives in the activity log instead.
            $table->text('review_remarks')->nullable();

            $table->foreignId('payment_voucher_batch_id')->nullable()->constrained('payment_voucher_batches')->nullOnDelete();

            // Formal revision chain (mirrors ProjectCertificate's Revise
            // pattern) — corrections after conversion create a NEW row
            // pointing back here rather than mutating locked data.
            $table->foreignId('revises_labour_list_id')->nullable()->constrained('labour_lists')->nullOnDelete();

            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('status');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_lists');
    }
};
