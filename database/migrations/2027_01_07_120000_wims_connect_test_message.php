<?php

use App\Models\Role;
use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMS Connect > Test Email / SMS: its switch in Global Feature Control (switched ON, under the WIMS Connect master switch) and its permission
 * "send-wims-connect-test", given to Super Admin and to every role that already manages WIMS Connect. Only ever adds; safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_features')) {
            $key = 'wims_connect_test';
            $f = SystemFeatureRegistry::features()[$key] ?? null;
            if ($f && ! DB::table('system_features')->where('key', $key)->exists()) {
                DB::table('system_features')->insert([
                    'key' => $key, 'name' => $f['name'], 'description' => $f['description'] ?? null, 'module' => $f['module'] ?? null, 'icon' => $f['icon'] ?? null,
                    'is_enabled' => 1, 'depends_on' => $f['depends_on'] ?? null, 'route_prefixes' => json_encode($f['route_prefixes'] ?? []),
                    'sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                FeatureManager::flushCache();
            }
        }

        $name = 'send-wims-connect-test';
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        Role::query()->get()->each(function ($role) use ($name) {
            if ($role->name === 'Super Admin' || $role->hasPermissionTo('manage-wims-connect')) {
                $role->givePermissionTo($name);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // the switch and the permission stay: removing them could change what people can do
    }
};
