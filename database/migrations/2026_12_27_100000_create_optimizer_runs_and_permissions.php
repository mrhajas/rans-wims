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
 * Settings > Optimize: the audit history of every optimisation (who ran it, what was done, what was gained), two permissions that nobody has by default (the Super
 * Admin passes every gate; any other role gets them on the Roles screen) and the Global Feature Control switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('optimizer_runs')) {
            Schema::create('optimizer_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_name')->nullable();          // kept as written, so the history still reads right if the user is renamed or removed
                $table->string('status', 20)->default('running');  // running, done, partial, failed
                $table->json('tasks')->nullable();                 // the keys that were run
                $table->json('options')->nullable();               // the numbers used (picture size, quality ...)
                $table->json('before')->nullable();                // sizes before
                $table->json('after')->nullable();                 // sizes after
                $table->json('results')->nullable();               // what each task did
                $table->json('speed_before')->nullable();
                $table->json('speed_after')->nullable();
                $table->bigInteger('saved_bytes')->default(0);
                $table->unsignedInteger('files_changed')->default(0);
                $table->unsignedInteger('tables_changed')->default(0);
                $table->decimal('speed_before_ms', 10, 2)->nullable();
                $table->decimal('speed_after_ms', 10, 2)->nullable();
                $table->unsignedInteger('seconds')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permissions')) {
            // created for nobody: only the Super Admin (who passes every gate) has them until a role is given them on the Roles screen
            foreach (['read-optimizer', 'run-optimizer'] as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (Schema::hasTable('system_features') && ($f = SystemFeatureRegistry::features()['optimizer'] ?? null)) {
            $exists = DB::table('system_features')->where('key', 'optimizer')->exists();
            DB::table('system_features')->updateOrInsert(['key' => 'optimizer'], [
                'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
            ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
            DB::table('system_features')->where('key', 'optimizer')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('optimizer_runs');
        Permission::query()->whereIn('name', ['read-optimizer', 'run-optimizer'])->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'optimizer')->delete();
        }
    }
};
