<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Optional one-to-one link between a system login (User) and an employee record (Staff).
    // Unique, so a login can belong to at most one staff profile and vice versa; NULL = not linked
    // (every existing staff row stays unlinked, nothing is changed for them).
    public function up(): void
    {
        Schema::table('staffs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('staff_number');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('staffs', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
