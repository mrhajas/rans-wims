<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Background Tasks: long READ-ONLY exports (PDF reports) run by a separate process so the person can keep working. Additive only: one new table and one
// Global Feature Control row (`background_tasks`); nothing existing is changed. `active_lock` is UNIQUE and filled only while a task is queued or running, which is
// what makes it impossible for the same person to have the same task twice at the same time (the database refuses the second one).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('background_tasks')) {
            Schema::create('background_tasks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('kind', 60);
                $t->string('label', 160);
                $t->string('subject_key', 120);
                $t->json('params')->nullable();
                $t->string('status', 12)->default('queued')->index(); // queued | running | done | failed | cancelled
                $t->unsignedTinyInteger('progress')->nullable();
                $t->string('message', 255)->nullable();
                $t->string('result_path', 255)->nullable();
                $t->string('result_name', 160)->nullable();
                $t->string('result_mime', 80)->nullable();
                $t->unsignedBigInteger('result_size')->nullable();
                $t->text('error')->nullable();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->unsignedInteger('pid')->nullable();
                $t->string('active_lock', 190)->nullable()->unique();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('last_heartbeat_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamp('dismissed_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'status']);
                $t->index(['status', 'created_at']);
            });
        }

        if (Schema::hasTable('system_features') && ($f = SystemFeatureRegistry::features()['background_tasks'] ?? null)) {
            $exists = DB::table('system_features')->where('key', 'background_tasks')->exists();
            DB::table('system_features')->updateOrInsert(['key' => 'background_tasks'], [
                'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
            ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
            DB::table('system_features')->where('key', 'background_tasks')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'background_tasks')->delete();
            FeatureManager::flushCache();
        }
    }
};
