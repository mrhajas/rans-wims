<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `amount` stays the contract-currency (TZS by default) equivalent — the
 * one figure every total/outstanding/status calculation already uses, so
 * nothing downstream has to change. `paid_currency`/`paid_amount`/
 * `exchange_rate` record what was ACTUALLY received when it differs from
 * the contract currency, purely for display (a tooltip showing "USD 5,000
 * @ 2,650.00 = TZS 13,250,000") — never fed back into the math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_certificate_payments', function (Blueprint $table) {
            $table->string('paid_currency', 10)->nullable()->after('amount');
            $table->decimal('paid_amount', 18, 2)->nullable()->after('paid_currency');
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('project_certificate_payments', function (Blueprint $table) {
            $table->dropColumn(['paid_currency', 'paid_amount', 'exchange_rate']);
        });
    }
};
