<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate time-of-day columns alongside the existing date-only
 * start_date/due_date — mirrors TaskReminder's own due_date + due_time
 * split (see TaskReminder/Index.vue's native <input type="time">) rather
 * than converting start_date/due_date to datetime columns, so every
 * existing day-granularity query/sort/filter on those two columns keeps
 * working unchanged; the time is purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('start_date');
            $table->time('due_time')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'due_time']);
        });
    }
};
