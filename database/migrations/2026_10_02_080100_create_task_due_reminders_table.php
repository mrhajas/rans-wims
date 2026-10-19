<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (task, user) once a due-date reminder has actually been sent —
 * its mere existence is the "don't send again" guard for
 * DispatchTaskDueReminders. Force-deleted (not soft) by TaskManagerController
 * when a task's due_date/due_time changes, so the reminder re-evaluates
 * against the new due time instead of staying silenced forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_due_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_due_reminders');
    }
};
