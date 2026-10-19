<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Balances an employee already carries when the company starts using WIMS:
     * unpaid salary, a loan still being repaid and/or an advance still being
     * recovered. One entry per employee. The loan/advance parts become real
     * (Active) salary_loans / salary_advances rows so the normal payroll
     * engine deducts them; the unpaid-salary part is added to the employee's
     * first prepared salary through the existing outstanding-adjustment ledger.
     */
    public function up(): void
    {
        Schema::create('salary_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();

            // The span of time the balance relates to (e.g. unpaid salary 1 Jan – 30 Sep 2026).
            $table->date('balance_from');
            $table->date('balance_to');

            $table->decimal('outstanding_salary', 15, 2)->default(0);
            $table->foreignId('outstanding_applied_record_id')->nullable()->constrained('salary_records')->nullOnDelete();
            $table->timestamp('outstanding_applied_at')->nullable();

            $table->decimal('loan_amount', 15, 2)->default(0);
            $table->unsignedInteger('loan_installments')->nullable();
            $table->foreignId('salary_loan_id')->nullable()->constrained('salary_loans')->nullOnDelete();

            $table->decimal('advance_amount', 15, 2)->default(0);
            $table->unsignedInteger('advance_installments')->nullable();
            $table->foreignId('salary_advance_id')->nullable()->constrained('salary_advances')->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_opening_balances');
    }
};
