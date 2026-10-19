<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->date('advance_date');
            $table->decimal('amount', 15, 2);
            $table->text('reason')->nullable();
            $table->string('repayment_method')->default('Fixed Installment');
            $table->unsignedInteger('installments_count')->default(1);
            $table->decimal('installment_amount', 15, 2);
            $table->foreignId('first_deduction_period_id')->nullable()->constrained('salary_periods')->nullOnDelete();
            $table->string('status', 32)->default('Pending');

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('salary_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained('salary_advances')->cascadeOnDelete();
            $table->foreignId('salary_record_id')->constrained('salary_records')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['salary_advance_id', 'salary_record_id'], 'sal_adv_repay_advance_record_unique');
        });

        Schema::create('salary_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->date('loan_date');
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->string('repayment_method')->default('Fixed Installment');
            $table->unsignedInteger('installments_count')->default(1);
            $table->decimal('installment_amount', 15, 2);
            $table->foreignId('first_deduction_period_id')->nullable()->constrained('salary_periods')->nullOnDelete();
            $table->string('status', 32)->default('Pending');

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('salary_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_loan_id')->constrained('salary_loans')->cascadeOnDelete();
            $table->foreignId('salary_record_id')->constrained('salary_records')->cascadeOnDelete();
            $table->decimal('principal_portion', 15, 2)->default(0);
            $table->decimal('interest_portion', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['salary_loan_id', 'salary_record_id'], 'sal_loan_repay_loan_record_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_loan_repayments');
        Schema::dropIfExists('salary_loans');
        Schema::dropIfExists('salary_advance_repayments');
        Schema::dropIfExists('salary_advances');
    }
};
