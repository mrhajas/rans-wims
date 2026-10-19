<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit binding to a real User account, since subscriber emails don't
     * always match the bound user's own account email (e.g. a subscriber
     * entry using a personal email while the user's WIMS account uses their
     * work email) — an email-match join isn't reliable enough on its own.
     */
    public function up(): void
    {
        Schema::table('warehouse_email_subscribers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
        });

        Schema::table('executive_report_subscribers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_email_subscribers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('executive_report_subscribers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
