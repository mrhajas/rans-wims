<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Task Reminders is a personal utility every user gets (see
     * resources/js/Core/menu.js — permissions: ['all']), but every
     * controller extending app/Http/Controllers/Controller.php auto-checks
     * a derived {action}-{route-prefix} permission via the Authorizable
     * trait regardless of that frontend-only flag — so every role needs
     * these granted, not just a curated subset like Construction Toolbox.
     */
    public function up(): void
    {
        $guard = 'web';
        $abilities = array_keys(PermissionRegistry::modules()['task_reminders']['abilities']);

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        Role::all()->each(function ($role) use ($abilities) {
            $role->givePermissionTo($abilities);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['task_reminders']['abilities']))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
