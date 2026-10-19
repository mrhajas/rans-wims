<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per account — the "Superadmin Alerts" toggle on
 * /task-manager-settings. When on, every Task Manager event guarantees
 * Super Admin creators/assignees get notified regardless of what the
 * regular TaskNotificationRule audience happens to be configured as (see
 * App\Services\TaskSuperadminAlertNotifier): SMS + Email for
 * assigned/status_changed, Email-only for commented/deleted/anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_superadmin_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_superadmin_alert_settings');
    }
};
