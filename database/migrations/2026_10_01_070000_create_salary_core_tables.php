<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_periods', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('batch_reference')->unique();
            $table->string('status', 32)->default('Draft');

            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['period_start', 'period_end']);
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->default(1);
            $table->string('key');
            $table->string('label');
            $table->string('type', 16); // earning | deduction
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'key']);
        });

        Schema::create('salary_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_period_id')->constrained('salary_periods')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();

            // Snapshotted at preparation time — never recalculated from
            // today's Staff record, so a later salary change never alters
            // an already-prepared historical period (see plan: Decision 2).
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('total_earnings', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('advance_deduction', 15, 2)->default(0);
            $table->decimal('loan_deduction', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2)->default(0);
            $table->decimal('opening_outstanding_balance', 15, 2)->default(0);
            $table->decimal('current_payable', 15, 2)->default(0);

            $table->string('status', 32)->default('Draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['salary_period_id', 'staff_id']);
            $table->index('status');
        });

        Schema::create('salary_record_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_record_id')->constrained('salary_records')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained('salary_components')->nullOnDelete();
            $table->string('label');
            $table->decimal('amount', 15, 2);
            $table->string('type', 16); // earning | deduction
            $table->string('source', 24)->default('component'); // component | advance | loan | outstanding | manual
            $table->timestamps();
        });

        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_record_id')->constrained('salary_records')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('paid_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('salary_voucher_id')->nullable(); // FK added once salary_vouchers exists
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('payment_date');
        });

        Schema::create('salary_outstanding_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_record_id')->constrained('salary_records')->cascadeOnDelete();
            $table->string('type', 16); // waiver | correction | settlement
            $table->decimal('amount', 15, 2); // signed — positive increases, negative reduces the opening balance
            $table->text('reason');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_outstanding_adjustments');
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('salary_record_items');
        Schema::dropIfExists('salary_records');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('salary_periods');
    }
};
