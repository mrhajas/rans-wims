<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per account — softcoded PAYE/NSSF/WCF/SDL rates, editable from
 * Salary Settings, never hardcoded (tax law changes). A missing row falls
 * back to SalaryStatutorySetting::forAccount()'s transient default (current
 * published Tanzania Mainland PAYE bands + 10%/10% NSSF + 0.5% WCF + 3.5%
 * SDL), same "auto-heal to defaults" convention as TaskNotificationRule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_statutory_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->boolean('is_enabled')->default(true);
            $table->json('paye_bands');
            $table->decimal('nssf_employee_rate', 6, 4)->default(0.1000);
            $table->decimal('nssf_employer_rate', 6, 4)->default(0.1000);
            $table->decimal('wcf_rate', 6, 4)->default(0.0050);
            $table->decimal('sdl_rate', 6, 4)->default(0.0350);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_statutory_settings');
    }
};
