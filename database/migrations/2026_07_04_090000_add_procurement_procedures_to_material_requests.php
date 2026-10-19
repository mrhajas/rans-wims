<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('material_request_items') && ! Schema::hasColumn('material_request_items', 'procedure')) {
            Schema::table('material_request_items', function (Blueprint $table) {
                $table->string('procedure', 32)->nullable()->after('priority');
            });
        }

        if (Schema::hasTable('material_requests') && ! Schema::hasColumn('material_requests', 'cleared_by_user_id')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->foreignId('cleared_by_user_id')->nullable()->after('approval_remarks')->constrained('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('material_requests') && ! Schema::hasColumn('material_requests', 'cleared_at')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->timestamp('cleared_at')->nullable()->after('cleared_by_user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('material_requests') && Schema::hasColumn('material_requests', 'cleared_at')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->dropColumn('cleared_at');
            });
        }

        if (Schema::hasTable('material_requests') && Schema::hasColumn('material_requests', 'cleared_by_user_id')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cleared_by_user_id');
            });
        }

        if (Schema::hasTable('material_request_items') && Schema::hasColumn('material_request_items', 'procedure')) {
            Schema::table('material_request_items', function (Blueprint $table) {
                $table->dropColumn('procedure');
            });
        }
    }
};
