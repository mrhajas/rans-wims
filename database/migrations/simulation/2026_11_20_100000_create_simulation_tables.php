<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables that exist ONLY in the simulation database (run by `php artisan simulation:install` / `simulation:migrate`, never by the normal migrate):
 * what was imported from production and from where, what a record looked like when it was imported, the scenarios and their checkpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('simulation_import_batches')) {
            Schema::create('simulation_import_batches', function (Blueprint $t) {
                $t->id();
                $t->string('reference', 30)->unique();
                $t->string('module', 60);
                $t->unsignedBigInteger('scenario_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('status', 14)->default('planned');          // planned | running | done | failed
                $t->json('selection')->nullable();
                $t->json('summary')->nullable();                       // counts per table, skipped, duplicates
                $t->json('problems')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('simulation_provenance')) {
            Schema::create('simulation_provenance', function (Blueprint $t) {
                $t->id();
                $t->string('table_name', 80);
                $t->unsignedBigInteger('sim_id');
                $t->string('state', 12)->default('synthetic');         // imported (unchanged copy) | modified | synthetic
                $t->unsignedBigInteger('source_id')->nullable();       // the production record this was copied from
                $t->unsignedBigInteger('batch_id')->nullable()->index();
                $t->unsignedBigInteger('scenario_id')->nullable()->index();
                $t->json('baseline')->nullable();                      // the record as it was when imported
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('modified_by')->nullable();
                $t->timestamp('modified_at')->nullable();
                $t->timestamps();
                $t->unique(['table_name', 'sim_id']);
                $t->index(['table_name', 'source_id']);
            });
        }
        if (! Schema::hasTable('simulation_scenarios')) {
            Schema::create('simulation_scenarios', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->text('description')->nullable();
                $t->string('status', 10)->default('active');           // active | archived
                $t->boolean('is_current')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('simulation_checkpoints')) {
            Schema::create('simulation_checkpoints', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('scenario_id')->index();
                $t->string('name', 120);
                $t->string('snapshot_main', 64);                       // the snapshot databases holding the copy
                $t->string('snapshot_accounts', 64);
                $t->unsignedInteger('tables')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('kind', 10)->default('manual');             // manual | baseline | auto
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['simulation_checkpoints', 'simulation_scenarios', 'simulation_provenance', 'simulation_import_batches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
