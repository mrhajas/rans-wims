<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('status', 50)->default('Rans Warehouse')->after('name');
        });

        // Set status to 'Rans Warehouse' for any existing warehouses
        DB::table('warehouses')->update(['status' => 'Rans Warehouse']);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
