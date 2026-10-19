<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('material_reallocations', 'reason_details')) {
            Schema::table('material_reallocations', function (Blueprint $table) {
                $table->text('reason_details')->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('material_reallocations', 'reason_details')) {
            Schema::table('material_reallocations', function (Blueprint $table) {
                $table->dropColumn('reason_details');
            });
        }
    }
};
