<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Health: how long pages and background calls really take. One row per hour, kind (page / api) and module, with the count, the total and the slowest time, the number of
 * errors and a small histogram, from which the typical time and the 95th / 99th percentile are read. No URL, user, query text or value is stored, only the module name (the first part of
 * the route name). Filled by `perf:aggregate` from a one-line-per-request file; rows older than 35 days are removed by the same command. Reversible: down() drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('perf_request_stats')) {
            return;
        }
        Schema::create('perf_request_stats', function (Blueprint $t) {
            $t->id();
            $t->timestamp('bucket')->index();
            $t->string('kind', 8);
            $t->string('module', 60);
            $t->unsignedInteger('n')->default(0);
            $t->unsignedBigInteger('sum_ms')->default(0);
            $t->unsignedInteger('max_ms')->default(0);
            $t->unsignedInteger('errors')->default(0);   // answers of 500 and above
            $t->unsignedInteger('client_errors')->default(0); // answers of 400 to 499
            $t->unsignedBigInteger('sum_queries')->default(0);
            for ($i = 0; $i < 10; $i++) {
                $t->unsignedInteger('h' . $i)->default(0);
            }
            $t->unique(['bucket', 'kind', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perf_request_stats');
    }
};
