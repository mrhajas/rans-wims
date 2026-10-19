<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App\Models\Model applies Illuminate's SoftDeletes trait unconditionally
 * (App\Traits\HasAttachments and the rest of the app's models all expect a
 * deleted_at column) — missed on the two lookup tables in the initial Case
 * Tracking migration since they don't track *who* deleted a row (no
 * $tracksDeletes / deleted_by need), but they still need deleted_at itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_categories', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('case_priorities', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('case_comments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('case_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('case_priorities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('case_comments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
