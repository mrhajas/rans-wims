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
            // Who physically made the payment — often the client, but not
            // always (a JV partner, a bank on the client's behalf, etc.), so
            // it's free text rather than forced onto the contract's own
            // client_contact_id.
            $table->string('paid_by', 190)->nullable()->after('method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_certificate_payments', function (Blueprint $table) {
            $table->dropColumn('paid_by');
        });
    }
};
