<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Total size of a diary's attachments, kept on the diary so the list can show and sort it without touching the media rows. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('site_diaries', 'media_bytes')) {
            Schema::table('site_diaries', function (Blueprint $table) {
                $table->unsignedBigInteger('media_bytes')->default(0)->after('videos_count');
            });
        }

        DB::statement('UPDATE site_diaries d SET media_bytes = COALESCE((SELECT SUM(m.file_size) FROM site_diary_media m WHERE m.site_diary_id = d.id AND m.deleted_at IS NULL), 0)');
    }

    public function down(): void
    {
        Schema::table('site_diaries', function (Blueprint $table) {
            $table->dropColumn('media_bytes');
        });
    }
};
