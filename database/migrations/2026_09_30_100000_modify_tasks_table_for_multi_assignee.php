<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Superseded by the task_assignees pivot (see
            // create_task_assignees_table) — safe to drop outright, this
            // module has zero real usage yet.
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropColumn('assigned_to_user_id');

            $table->date('start_date')->nullable()->after('description');
            $table->string('category')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'category']);
            $table->foreignId('assigned_to_user_id')->nullable()->after('position')->constrained('users')->nullOnDelete();
        });
    }
};
