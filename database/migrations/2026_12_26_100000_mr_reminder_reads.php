<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which Material Request reminders each person has marked as read (the number on the bell counts the unread ones). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mr_reminder_reads')) {
            Schema::create('mr_reminder_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reminder_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at')->nullable();
                $table->unique(['reminder_id', 'user_id']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_reminder_reads');
    }
};
