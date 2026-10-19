<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('material_requests', 'force_cleared')) {
            Schema::table('material_requests', function (Blueprint $table) {
                // Set when a Super Admin cleared the request in one step, skipping issue / delivery ("Admin Force Clearance"). Otherwise it is a normal Cleared request.
                $table->boolean('force_cleared')->default(false)->after('cleared_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('material_requests', 'force_cleared')) {
            Schema::table('material_requests', fn (Blueprint $table) => $table->dropColumn('force_cleared'));
        }
    }
};
