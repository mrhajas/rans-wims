<?php

use App\Models\Account;
use App\Models\TaskNotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the due-date reminder's own timing knob (0/8/12/24 hours before due)
 * and seeds the new "due_reminder" rule for every existing account, mirroring
 * how 2026_09_30_120000_create_task_notification_rules_table.php seeded the
 * original three events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_notification_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_offset_hours')->nullable()->after('sms_enabled');
        });

        Account::all(['id'])->each(function ($account) {
            TaskNotificationRule::firstOrCreate(
                ['account_id' => $account->id, 'event_key' => TaskNotificationRule::EVENT_DUE_REMINDER],
                // only the columns that exist at this point: the model's defaults also carry later columns
                array_intersect_key(TaskNotificationRule::defaults()[TaskNotificationRule::EVENT_DUE_REMINDER], array_flip(Schema::getColumnListing('task_notification_rules')))
            );
        });
    }

    public function down(): void
    {
        TaskNotificationRule::where('event_key', TaskNotificationRule::EVENT_DUE_REMINDER)->delete();

        Schema::table('task_notification_rules', function (Blueprint $table) {
            $table->dropColumn('reminder_offset_hours');
        });
    }
};
