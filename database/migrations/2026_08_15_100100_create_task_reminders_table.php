<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->dateTime('due_at');
            $table->string('priority')->default('normal');
            $table->boolean('is_completed')->default(false);
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('snoozed_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'user_id']);
            $table->index(['due_at', 'notified_at', 'is_completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
