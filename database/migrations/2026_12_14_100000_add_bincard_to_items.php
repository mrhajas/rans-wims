<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a BinCard is a photo of the item's bin card (the stock card kept with the goods): one is current, the older ones stay as history
        if (! Schema::hasColumn('item_photos', 'kind')) {
            Schema::table('item_photos', function (Blueprint $table) {
                $table->string('kind', 10)->default('photo')->after('path')->index(); // photo | bincard
                $table->unsignedBigInteger('user_id')->nullable()->after('position');
            });
        }
        if (! Schema::hasColumn('items', 'bincard_photo_id')) {
            Schema::table('items', function (Blueprint $table) {
                $table->unsignedBigInteger('bincard_photo_id')->nullable();
                $table->timestamp('bincard_at')->nullable();
                $table->unsignedBigInteger('bincard_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['bincard_photo_id', 'bincard_at', 'bincard_by']);
        });
        Schema::table('item_photos', function (Blueprint $table) {
            $table->dropColumn(['kind', 'user_id']);
        });
    }
};
