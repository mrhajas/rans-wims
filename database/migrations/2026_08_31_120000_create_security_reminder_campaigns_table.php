<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "Send Reminder" action. Deliberately plain Eloquent / no
 * account_id, matching bulk_sms_messages and the rest of these ad-hoc
 * admin-communication tables rather than the account-scoped base Model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_reminder_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->enum('recipient_mode', ['all', 'selected'])->default('selected');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_reminder_campaigns');
    }
};
