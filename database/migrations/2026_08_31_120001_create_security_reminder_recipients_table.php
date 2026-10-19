<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user targeted by a campaign — also the audit trail of whether
 * each user had 2FA enabled at the exact moment they were reminded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_reminder_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('security_reminder_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('two_factor_enabled_at_send')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_reminder_recipients');
    }
};
