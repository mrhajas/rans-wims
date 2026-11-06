<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMS Logs (Settings > WIMS Logs): old log records are moved out of the live tables into compressed archive files that a Super Admin can read, and this is the
 * record of it: one row for each run, one row for each log archived in a run (how many records, their size before and after, where the file is, its checksum).
 * Two permissions that nobody has by default (the Super Admin passes every gate) and the Global Feature Control switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wims_log_runs')) {
            Schema::create('wims_log_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_name')->nullable();           // kept as written, so the history still reads right if the user is renamed or removed
                $table->string('trigger', 12)->default('manual');   // schedule, manual
                $table->string('status', 12)->default('running');   // running, done, partial, failed
                $table->json('sources')->nullable();                // the logs this run looked at
                $table->json('options')->nullable();                // the days each log stays live, used by this run
                $table->json('before')->nullable();                 // database size before
                $table->json('after')->nullable();                  // database size after
                $table->json('speed_before')->nullable();
                $table->json('speed_after')->nullable();
                $table->decimal('speed_before_ms', 10, 2)->nullable();
                $table->decimal('speed_after_ms', 10, 2)->nullable();
                $table->unsignedBigInteger('rows_archived')->default(0);
                $table->unsignedBigInteger('original_bytes')->default(0);   // what those records weighed in the database
                $table->unsignedBigInteger('stored_bytes')->default(0);     // what they weigh in the archive files (data and index)
                $table->bigInteger('db_freed_bytes')->default(0);           // how much smaller the database files really became (after the tables were compacted)
                $table->unsignedInteger('seconds')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wims_log_archives')) {
            Schema::create('wims_log_archives', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->string('source', 30)->index();              // weather, delivery, downloads, alerts, slow
                $table->string('table_name', 64);
                $table->unsignedBigInteger('rows_n')->default(0);
                $table->unsignedBigInteger('original_bytes')->default(0);   // the records as they were in the database
                $table->unsignedBigInteger('compressed_bytes')->default(0); // the same records compressed, one by one
                $table->unsignedBigInteger('stored_bytes')->default(0);     // the files on disk: compressed records plus the index
                $table->timestamp('date_from')->nullable();
                $table->timestamp('date_to')->nullable();
                $table->string('path')->nullable();                 // relative to storage/app/wims-logs
                $table->string('sha256', 64)->nullable();           // of the data file, taken when the archive was closed
                $table->boolean('verified')->default(false);        // every record was read back and compared before it was removed from the live table
                $table->unsignedBigInteger('skipped_n')->default(0); // records that could not be archived safely and were left live
                $table->unsignedBigInteger('restored_n')->default(0);
                $table->timestamp('restored_at')->nullable();
                $table->boolean('closed')->default(false);
                $table->text('error')->nullable();
                $table->timestamps();
                $table->index(['run_id', 'source']);
            });
        }

        if (Schema::hasTable('permissions')) {
            foreach (['read-wims-logs', 'run-wims-logs'] as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (Schema::hasTable('system_features') && ($f = SystemFeatureRegistry::features()['wims_logs'] ?? null)) {
            $exists = DB::table('system_features')->where('key', 'wims_logs')->exists();
            DB::table('system_features')->updateOrInsert(['key' => 'wims_logs'], [
                'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
            ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
            DB::table('system_features')->where('key', 'wims_logs')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wims_log_archives');
        Schema::dropIfExists('wims_log_runs');
        Permission::query()->whereIn('name', ['read-wims-logs', 'run-wims-logs'])->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'wims_logs')->delete();
        }
    }
};
