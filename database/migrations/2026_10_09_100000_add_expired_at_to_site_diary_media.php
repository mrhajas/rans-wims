<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // set when the file itself was erased from Google Drive (retention period reached, or "Clear Drive"); the record stays
        Schema::table('site_diary_media', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->after('purged_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_diary_media', function (Blueprint $table) {
            $table->dropColumn('expired_at');
        });
    }
};
