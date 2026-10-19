<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('material_request_status_logs') && ! Schema::hasColumn('material_request_status_logs', 'deleted_at')) {
            Schema::table('material_request_status_logs', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('material_request_status_logs') && Schema::hasColumn('material_request_status_logs', 'deleted_at')) {
            Schema::table('material_request_status_logs', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
