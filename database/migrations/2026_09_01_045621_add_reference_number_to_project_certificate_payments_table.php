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
        Schema::table('project_certificate_payments', function (Blueprint $table) {
            // The user-supplied external reference (bank slip / transaction
            // / cheque number) — distinct from `reference`, which is the
            // system-generated RCT-{project code}-{seq} receipt number (see
            // ProjectCertificatePayment::reference()) and is never
            // user-editable.
            $table->string('reference_number', 190)->nullable()->after('paid_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_certificate_payments', function (Blueprint $table) {
            $table->dropColumn('reference_number');
        });
    }
};
