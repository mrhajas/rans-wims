<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('asset_expiry_email_enabled')->default(true)->after('task_reminders_email_enabled');
            $table->boolean('compliance_reminder_email_enabled')->default(true)->after('asset_expiry_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['asset_expiry_email_enabled', 'compliance_reminder_email_enabled']);
        });
    }
};
