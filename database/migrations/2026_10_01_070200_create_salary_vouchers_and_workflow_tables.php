<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number')->unique();
            $table->string('type', 16); // individual | bulk
            $table->foreignId('salary_period_id')->constrained('salary_periods')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staffs')->nullOnDelete(); // null for the bulk master voucher
            $table->foreignId('parent_id')->nullable()->constrained('salary_vouchers')->nullOnDelete();

            // Frozen snapshot at generation time — deliberately NOT live-joined,
            // so a printed/downloaded voucher never changes after the fact.
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2)->default(0);
            $table->decimal('opening_outstanding_balance', 15, 2)->default(0);
            $table->decimal('current_payment', 15, 2)->default(0);
            $table->decimal('remaining_balance', 15, 2)->default(0);

            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('payment_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();

            $table->unsignedInteger('printed_count')->default(0);
            $table->timestamp('first_printed_at')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
        });

        Schema::table('salary_payments', function (Blueprint $table) {
            $table->foreign('salary_voucher_id')->references('id')->on('salary_vouchers')->nullOnDelete();
        });

        Schema::create('salary_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->default(1);
            $table->string('step_key'); // prepare | approve | pay
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'step_key']);
        });

        Schema::create('salary_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->default(1);
            $table->string('event_key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('notify_staff')->default(false);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_notification_rules');
        Schema::dropIfExists('salary_workflow_steps');

        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropForeign(['salary_voucher_id']);
        });

        Schema::dropIfExists('salary_vouchers');
    }
};
