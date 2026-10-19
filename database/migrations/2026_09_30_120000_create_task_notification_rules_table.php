<?php

use App\Models\Account;
use App\Models\TaskNotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Softcoded "who gets notified" for Task Manager — mirrors
 * case_tracking_notification_rules exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('event_key', 50);
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('notify_creator')->default(false);
            $table->boolean('notify_assignee')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'event_key']);
        });

        // Seeded to reproduce today's default behavior (assignee notified on
        // assignment, creator+assignees notified on status change) — nothing
        // changes for anyone until a Super Admin edits a rule.
        Account::all(['id'])->each(function ($account) {
            foreach (TaskNotificationRule::defaults() as $eventKey => $rule) {
                TaskNotificationRule::firstOrCreate(
                    ['account_id' => $account->id, 'event_key' => $eventKey],
                    // only the columns that exist at this point: the model's defaults also carry later columns
                    array_intersect_key($rule, array_flip(Schema::getColumnListing('task_notification_rules')))
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_notification_rules');
    }
};
