<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's local network IP (best-effort, via a WebRTC ICE-candidate
     * probe) alongside the server-observed public IP already captured in
     * clock_in_ip/clock_out_ip — browsers increasingly obfuscate this behind
     * an mDNS hostname or block it outright, so it's nullable and never
     * required.
     */
    public function up(): void
    {
        Schema::table('digital_attendance_records', function (Blueprint $table) {
            $table->string('clock_in_local_ip', 64)->nullable()->after('clock_in_ip');
            $table->string('clock_out_local_ip', 64)->nullable()->after('clock_out_ip');
        });
    }

    public function down(): void
    {
        Schema::table('digital_attendance_records', function (Blueprint $table) {
            $table->dropColumn(['clock_in_local_ip', 'clock_out_local_ip']);
        });
    }
};
