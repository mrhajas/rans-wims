<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a photo / video is removed from a diary its file can also be deleted from storage (Google Drive Trash and the
 * WIMS server). purged_at records that the file itself is gone. Files removed BEFORE this feature existed are marked
 * as already handled, so switching it on never sweeps files people removed earlier.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('site_diary_media', 'purged_at')) {
            Schema::table('site_diary_media', function (Blueprint $table) {
                $table->timestamp('purged_at')->nullable()->after('deleted_by');
            });
        }
        DB::table('site_diary_media')->whereNotNull('deleted_at')->whereNull('purged_at')->update(['purged_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('site_diary_media', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
