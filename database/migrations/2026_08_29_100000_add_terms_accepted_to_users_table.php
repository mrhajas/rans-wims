<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks the highest Terms & Conditions version each user has viewed —
     * a single-row "latest state" column pair, same shape as last_seen_at.
     * Bumping the terms_version Setting (see SettingController::updateTerms)
     * makes every user's stored version stale again, so TermsController@show
     * re-logs a fresh acknowledgment on their next visit.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('terms_accepted_version')->nullable()->after('last_seen_at');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_accepted_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_version', 'terms_accepted_at']);
        });
    }
};
