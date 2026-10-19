<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('boq_material_compliances')) {
            return;
        }

        Schema::table('boq_material_compliances', function (Blueprint $table) {
            if (! Schema::hasColumn('boq_material_compliances', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('boq_material_compliances') || ! Schema::hasColumn('boq_material_compliances', 'deleted_at')) {
            return;
        }

        Schema::table('boq_material_compliances', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
