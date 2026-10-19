<?php

use App\Models\Account;
use App\Models\CaseTrackingNotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Softcoded "who gets notified" for Case Tracking — mirrors
 * material_request_notification_rules exactly, with one addition:
 * Case Tracking has an ASSIGNEE as well as a creator, so notify_requester
 * becomes two flags here (notify_creator / notify_assignee) instead of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_tracking_notification_rules', function (Blueprint $table) {
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

        // Seeded to reproduce today's exact hardcoded behavior — nothing
        // changes for anyone until a Super Admin edits a rule.
        Account::all(['id'])->each(function ($account) {
            foreach (CaseTrackingNotificationRule::defaults() as $eventKey => $rule) {
                CaseTrackingNotificationRule::firstOrCreate(
                    ['account_id' => $account->id, 'event_key' => $eventKey],
                    // only the columns that exist at this point: the model's defaults also carry later columns
                    array_intersect_key($rule, array_flip(Schema::getColumnListing('case_tracking_notification_rules')))
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_tracking_notification_rules');
    }
};
