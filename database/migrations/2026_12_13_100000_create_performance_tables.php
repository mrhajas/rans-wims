<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the activity archive: same columns and indexes as the live activity log, so rows move across unchanged (the id stays the same) and nothing is ever deleted
        if (! Schema::hasTable('activity_log_archive') && Schema::hasTable('activity_log')) {
            DB::statement('CREATE TABLE activity_log_archive LIKE activity_log');
            // a table that is only ever appended to needs a plain id, not a counter
            DB::statement('ALTER TABLE activity_log_archive MODIFY id BIGINT UNSIGNED NOT NULL');
            Schema::table('activity_log_archive', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('account_id');
            });
        }

        // slow queries and slow pages seen while people work (Settings > Performance & Data)
        if (! Schema::hasTable('slow_logs')) {
            Schema::create('slow_logs', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 10); // query | request
                $table->text('label'); // the query with ? in place of values, or "GET route.name"
                $table->string('fingerprint', 40)->index();
                $table->unsignedInteger('duration_ms');
                $table->string('path', 255)->nullable();
                $table->string('method', 8)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedSmallInteger('queries')->nullable();
                $table->unsignedSmallInteger('memory_mb')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        // messages and e-mails sent in the background (used only when "Send in the background" is switched on in Settings)
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('slow_logs');
        Schema::dropIfExists('activity_log_archive');
    }
};
