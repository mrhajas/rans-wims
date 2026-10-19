<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Case Tracking's notification rules never had email/SMS toggles at all —
 * only the in-app "database" channel ever fired (see
 * App\Listeners\CaseTicketAssignedListener, which never set `send_email` on
 * its AppNotification payload because there was nothing to read it from).
 * Mirrors task_notification_rules' channel columns exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_tracking_notification_rules', function (Blueprint $table) {
            $table->boolean('email_enabled')->default(true)->after('notify_assignee');
            $table->boolean('sms_enabled')->default(false)->after('email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('case_tracking_notification_rules', function (Blueprint $table) {
            $table->dropColumn(['email_enabled', 'sms_enabled']);
        });
    }
};
