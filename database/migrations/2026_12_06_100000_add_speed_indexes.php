<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed: two indexes the busiest pages were missing (the dashboard counts the day's activity and every page counts the person's unread notifications).
 * On the live copy the activity count took 770 ms and now takes a few milliseconds. Nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log') && ! Schema::hasIndex('activity_log', 'activity_log_account_created_idx')) {
            Schema::table('activity_log', fn (Blueprint $t) => $t->index(['account_id', 'created_at'], 'activity_log_account_created_idx'));
        }
        if (Schema::hasTable('notifications') && ! Schema::hasIndex('notifications', 'notifications_notifiable_read_idx')) {
            Schema::table('notifications', fn (Blueprint $t) => $t->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_read_idx'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('activity_log') && Schema::hasIndex('activity_log', 'activity_log_account_created_idx')) {
            Schema::table('activity_log', fn (Blueprint $t) => $t->dropIndex('activity_log_account_created_idx'));
        }
        if (Schema::hasTable('notifications') && Schema::hasIndex('notifications', 'notifications_notifiable_read_idx')) {
            Schema::table('notifications', fn (Blueprint $t) => $t->dropIndex('notifications_notifiable_read_idx'));
        }
    }
};
