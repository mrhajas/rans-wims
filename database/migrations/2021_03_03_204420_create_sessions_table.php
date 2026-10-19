<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreated from the live schema — the original migration file that created
 * this table was missing from database/migrations/ even though the
 * `migrations` table still recorded it as run (batch 1) on this database.
 * On this environment `migrate` just skips it (already recorded); a fresh
 * deploy needs the file present or SESSION_DRIVER=database breaks login
 * immediately with no `sessions` table to write to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
