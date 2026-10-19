<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A reversal is a new negative-amount row referencing the original — this is the "references it" link the model's own docblock already described but no code ever acted on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->foreignId('reverses_payment_id')->nullable()->after('salary_voucher_id')->constrained('salary_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_payment_id');
        });
    }
};
