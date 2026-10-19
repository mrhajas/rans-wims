<?php

use App\Models\Role;
use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMS Smart Brief:
 *  1. the table of explicit "who may view whose brief" assignments (the softcoded, user-to-user part of the access control),
 *  2. its abilities (created from PermissionRegistry; only "view own" is given to every role, Super Admin passes every check anyway),
 *  3. its rows in Global Feature Control (a parent and one row per sub-function, read from SystemFeatureRegistry).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smart_brief_access_assignments')) {
            Schema::create('smart_brief_access_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('viewer_user_id')->index();
                $table->unsignedBigInteger('subject_user_id')->index();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('granted_by_user_id')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['viewer_user_id', 'subject_user_id', 'revoked_at'], 'sb_assign_lookup');
            });
        }

        // 2. abilities
        $names = array_keys(PermissionRegistry::modules()['smart_brief']['abilities'] ?? []);
        foreach ($names as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        if ($own = Permission::query()->where('name', 'view-own-smart-brief')->where('guard_name', 'web')->first()) {
            Role::query()->get()->each(fn ($role) => $role->givePermissionTo($own));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 3. Global Feature Control rows
        if (Schema::hasTable('system_features')) {
            $order = (int) DB::table('system_features')->max('sort_order');
            foreach (SystemFeatureRegistry::features() as $key => $f) {
                if (! str_starts_with($key, 'smart_brief')) {
                    continue;
                }
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null,
                    'sort_order' => ++$order, 'updated_at' => now(), 'created_at' => now(),
                ]);
            }
            DB::table('system_features')->where('key', 'like', 'smart_brief%')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'like', 'smart_brief%')->delete();
            FeatureManager::flushCache();
        }
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['smart_brief']['abilities'] ?? []))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('smart_brief_access_assignments');
    }
};
