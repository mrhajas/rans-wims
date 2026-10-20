<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Where the sessions table was created with started_at as a NOT NULL timestamp, MySQL made it move to "now" at every save: make it a plain nullable timestamp. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adjustment_sessions') && DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE adjustment_sessions MODIFY started_at TIMESTAMP NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
    }
};
