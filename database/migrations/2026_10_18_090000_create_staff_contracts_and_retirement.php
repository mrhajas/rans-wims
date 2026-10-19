<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employment contracts of a staff member: one active at a time, renewals link back to what they replace.
        Schema::create('staff_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 20)->unique();
            $table->foreignId('staff_id')->constrained('staffs')->cascadeOnDelete();
            $table->string('type', 40)->default('Fixed term');
            $table->string('position', 190)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();            // empty = open ended (a permanent contract)
            $table->date('signed_date')->nullable();
            $table->decimal('basic_salary', 15, 2)->nullable();
            $table->unsignedSmallInteger('probation_months')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->string('status', 16)->default('Active'); // Active | Renewed | Ended
            $table->unsignedBigInteger('renewed_from_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('last_reminder_days')->nullable(); // the "days left" step already reminded about
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['staff_id', 'status']);
            $table->index('end_date');
        });

        // Retirement: "Retired" leaves payroll, "Retired Active" is retired but still paid through payroll.
        Schema::table('staffs', function (Blueprint $table) {
            $table->date('retired_at')->nullable()->after('status');
            $table->text('retirement_note')->nullable()->after('retired_at');
        });
    }

    public function down(): void
    {
        Schema::table('staffs', function (Blueprint $table) {
            $table->dropColumn(['retired_at', 'retirement_note']);
        });
        Schema::dropIfExists('staff_contracts');
    }
};
