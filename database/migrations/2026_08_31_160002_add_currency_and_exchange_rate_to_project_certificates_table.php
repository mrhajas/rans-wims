<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_certificates', function (Blueprint $table) {
            // The currency the QS actually entered the calculation lines in
            // (defaults to the contract's own currency) and the day's rate
            // to convert that into TZS — RANS reports centrally in TZS
            // regardless of which currency an individual contract is
            // denominated in. Mirrors the same pattern already used for
            // foreign-currency payments (project_certificate_payments).
            $table->string('currency', 10)->nullable()->after('net_payment_amount');
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_certificates', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
