<?php

use App\Models\TaskNotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-event delivery-channel toggles (email, SMS) alongside the
 * existing "who" (role/user/creator/assignee) targeting — the in-app bell
 * stays always-on and isn't a toggle, matching every other module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_notification_rules', function (Blueprint $table) {
            $table->boolean('email_enabled')->default(true)->after('notify_assignee');
            $table->boolean('sms_enabled')->default(false)->after('email_enabled');
        });

        // Backfill existing rows to the new defaults() shape so every
        // account's rules immediately reflect the intended email/SMS
        // behavior instead of sitting at the column default only.
        foreach (TaskNotificationRule::defaults() as $eventKey => $rule) {
            TaskNotificationRule::where('event_key', $eventKey)->update([
                'email_enabled' => $rule['email_enabled'],
                'sms_enabled' => $rule['sms_enabled'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('task_notification_rules', function (Blueprint $table) {
            $table->dropColumn(['email_enabled', 'sms_enabled']);
        });
    }
};
