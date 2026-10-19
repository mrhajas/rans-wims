<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clocking in again after a same-day clock-out reopens the existing
     * day record rather than creating a second row — these columns let
     * that reopened session preserve the prior session(s)' worked time
     * and the day's true first clock-in, instead of overwriting them.
     */
    public function up(): void
    {
        Schema::table('digital_attendance_records', function (Blueprint $table) {
            $table->unsignedInteger('accumulated_minutes')->default(0)->after('worked_minutes');
            $table->unsignedInteger('sessions_count')->default(1)->after('accumulated_minutes');
            $table->dateTime('first_clock_in_at')->nullable()->after('sessions_count');
        });
    }

    public function down(): void
    {
        Schema::table('digital_attendance_records', function (Blueprint $table) {
            $table->dropColumn(['accumulated_minutes', 'sessions_count', 'first_clock_in_at']);
        });
    }
};
