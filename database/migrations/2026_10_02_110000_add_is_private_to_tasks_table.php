<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Super Admin-created personal task ("My Tasks") can be marked private —
 * visible only to its creator and current assignees, excluding every other
 * viewer regardless of role/permission (Admin, read-all-task-manager, even
 * another Super Admin). Only ever set at creation time by
 * TaskManagerController::store(); never client-settable directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
