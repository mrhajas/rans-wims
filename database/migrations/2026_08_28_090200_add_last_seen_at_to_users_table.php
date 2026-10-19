<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lightweight per-user presence heartbeat (spec §20) — deliberately
     * distinct from Digital Attendance's clock-in/out status, and from the
     * app-wide network connectivity indicator (resources/js/Core/offlineStatus.js).
     * "Presence" here only means "this browser session pinged recently."
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
