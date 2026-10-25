<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('item_photos', 'cutout')) {
            Schema::table('item_photos', function (Blueprint $table) {
                $table->boolean('cutout')->default(false)->after('kind'); // the background of this photo was already removed
            });
        }
    }

    public function down(): void
    {
        Schema::table('item_photos', function (Blueprint $table) {
            $table->dropColumn('cutout');
        });
    }
};
