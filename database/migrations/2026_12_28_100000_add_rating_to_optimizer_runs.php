<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The general status of an optimisation (Excellent / Very Good / Good / Needs attention), why, and what was still left to gain after it. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('optimizer_runs') && ! Schema::hasColumn('optimizer_runs', 'rating')) {
            Schema::table('optimizer_runs', function (Blueprint $table) {
                $table->string('rating', 20)->nullable()->after('status');
                $table->json('rating_why')->nullable()->after('rating');
                $table->json('remaining')->nullable()->after('rating_why');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('optimizer_runs', 'rating')) {
            Schema::table('optimizer_runs', function (Blueprint $table) {
                $table->dropColumn(['rating', 'rating_why', 'remaining']);
            });
        }
    }
};
