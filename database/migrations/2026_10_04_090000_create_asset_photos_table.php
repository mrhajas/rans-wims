<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An asset may now carry up to 3 photos. They live in their own table
     * (one row per photo, ordered by `position`); `assets.photo` is kept as
     * the "cover" (first photo) so list views that only read that column keep
     * working. Existing single photos are copied across as photo #1.
     */
    public function up(): void
    {
        Schema::create('asset_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['asset_id', 'position']);
        });

        DB::table('assets')
            ->whereNotNull('photo')
            ->where('photo', '!=', '')
            ->orderBy('id')
            ->get(['id', 'photo'])
            ->each(function ($asset) {
                DB::table('asset_photos')->insert([
                    'asset_id' => $asset->id,
                    'path' => $asset->photo,
                    'position' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_photos');
    }
};
