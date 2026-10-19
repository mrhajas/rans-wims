<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per compose+send action (a "blast"). Counters are denormalized
     * so the History list doesn't need to aggregate bulk_sms_recipients on
     * every page load. Deliberately plain Eloquent / no account_id, matching
     * the rest of the WimsConnect subsystem (wims_connect_logs etc.) rather
     * than the account-scoped base Model.
     */
    public function up(): void
    {
        Schema::create('bulk_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->enum('status', ['Draft', 'Scheduled', 'Sending', 'Completed', 'Cancelled'])->default('Draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_sms_messages');
    }
};
