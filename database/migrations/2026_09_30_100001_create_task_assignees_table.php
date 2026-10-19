<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            // assignee_type / assignee_id — a task can be assigned to a
            // User (logged-in WIMS account), a Staff (HR record, no login),
            // or a Contact (external party, no login). No existing
            // polymorphic-assignable pattern exists elsewhere in this app
            // (every other "Staff or Contact" case is single-valued), so
            // this is Eloquent's standard morphTo shape rather than a
            // hand-rolled variant.
            $table->morphs('assignee');
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            // Every model extending the app's base App\Models\Model gets
            // SoftDeletes unconditionally (see App\Models\Model), so this
            // pivot-like table needs the column too.
            $table->softDeletes();

            $table->unique(['task_id', 'assignee_type', 'assignee_id'], 'task_assignees_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
    }
};
