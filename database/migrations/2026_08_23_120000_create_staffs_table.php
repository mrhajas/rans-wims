<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffs', function (Blueprint $table) {
            $table->id();
            $table->string('staff_number')->unique();
            $table->string('photo')->nullable();

            // Personal Information
            $table->string('name');
            $table->string('qualification')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('employment_type', 32)->nullable();

            // Contact Details
            $table->text('address')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('mobile_no')->nullable();

            // Family Info
            $table->string('father_name')->nullable();
            $table->string('father_contact_no')->nullable();

            // Bank Details
            $table->string('bank_name')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('account_no')->nullable();

            // Employment & Salary
            $table->string('position')->nullable();
            $table->date('join_date')->nullable();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('allowances', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2)->default(0);
            $table->string('status', 16)->default('Active');

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staffs');
    }
};
