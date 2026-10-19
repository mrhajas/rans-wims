<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notification Center: for each notification, which channels (bell, message, email), who gets it and when.
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('event_key', 80);
            $table->boolean('enabled')->default(true);
            $table->boolean('bell')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('email')->default(false);
            $table->json('user_ids')->nullable();
            $table->json('role_names')->nullable();
            $table->boolean('skip_actor')->default(false);
            $table->string('delivery', 12)->default('immediate');   // immediate | scheduled
            $table->string('send_time', 5)->nullable();             // HH:MM, East Africa Time
            $table->json('send_days')->nullable();                  // 0 = Sunday ... 6 = Saturday; empty = every day
            $table->timestamp('last_sent_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['account_id', 'event_key']);
        });

        // Notifications waiting for their set time.
        Schema::create('notification_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('notification_rules')->cascadeOnDelete();
            $table->string('event_key', 80);
            $table->json('payload');
            $table->timestamp('due_at')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_queue');
        Schema::dropIfExists('notification_rules');
    }
};
