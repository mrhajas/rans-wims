<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_staff_id']);
            $table->renameColumn('assigned_to_staff_id', 'assigned_to_user_id');
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('asset_movements', function (Blueprint $table) {
            $table->dropForeign(['from_responsible_staff_id']);
            $table->dropForeign(['to_responsible_staff_id']);
            $table->renameColumn('from_responsible_staff_id', 'from_responsible_user_id');
            $table->renameColumn('to_responsible_staff_id', 'to_responsible_user_id');
        });
        Schema::table('asset_movements', function (Blueprint $table) {
            $table->foreign('from_responsible_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('to_responsible_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_movements', function (Blueprint $table) {
            $table->dropForeign(['from_responsible_user_id']);
            $table->dropForeign(['to_responsible_user_id']);
            $table->renameColumn('from_responsible_user_id', 'from_responsible_staff_id');
            $table->renameColumn('to_responsible_user_id', 'to_responsible_staff_id');
        });
        Schema::table('asset_movements', function (Blueprint $table) {
            $table->foreign('from_responsible_staff_id')->references('id')->on('staffs')->nullOnDelete();
            $table->foreign('to_responsible_staff_id')->references('id')->on('staffs')->nullOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_user_id']);
            $table->renameColumn('assigned_to_user_id', 'assigned_to_staff_id');
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('assigned_to_staff_id')->references('id')->on('staffs')->nullOnDelete();
        });
    }
};
