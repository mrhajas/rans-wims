<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('project_manager', 190)->nullable()->after('other_info');
            $table->string('project_category', 100)->nullable()->after('project_manager');
            $table->string('floors_units', 100)->nullable()->after('project_category');
            $table->string('priority_level', 20)->nullable()->after('floors_units');
            $table->unsignedInteger('estimated_workforce')->nullable()->after('priority_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['project_manager', 'project_category', 'floors_units', 'priority_level', 'estimated_workforce']);
        });
    }
};
