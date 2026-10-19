<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip must be verified before it can be marked paid or added to a
 * Payment Voucher, and an unverified trip's amount is excluded from every
 * dashboard/report money total — the same exclusion pattern already used
 * for Cancelled trips (see TransportDashboardController/TransportReportController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('amount_changed_at');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn('verified_at');
        });
    }
};
