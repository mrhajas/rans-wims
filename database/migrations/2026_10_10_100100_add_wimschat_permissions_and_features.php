<?php

use App\Models\Role;
use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMSChat abilities (created for everyone to be granted from Roles & Permissions; only the Super Admin gets them up
 * front) and the Global Feature Control rows. Re-running is safe.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return array_keys(PermissionRegistry::modules()['wimschat']['abilities']);
    }

    private function featureKeys(): array
    {
        return array_values(array_filter(array_keys(SystemFeatureRegistry::features()), fn ($k) => str_starts_with($k, 'wimschat')));
    }

    public function up(): void
    {
        foreach ($this->abilities() as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo($this->abilities());
        }

        $features = SystemFeatureRegistry::features();
        $order = (int) DB::table('system_features')->max('sort_order');
        foreach ($this->featureKeys() as $key) {
            $f = $features[$key];
            DB::table('system_features')->updateOrInsert(['key' => $key], [
                'name' => $f['name'],
                'description' => $f['description'],
                'module' => $f['module'],
                'icon' => $f['icon'],
                'is_enabled' => true,
                'sort_order' => ++$order,
                'route_prefixes' => json_encode($f['route_prefixes']),
                'depends_on' => $f['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        FeatureManager::flushCache();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();
        DB::table('system_features')->whereIn('key', $this->featureKeys())->delete();
        FeatureManager::flushCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
