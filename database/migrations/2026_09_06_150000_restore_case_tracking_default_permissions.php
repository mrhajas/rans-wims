<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from PermissionRegistry::modules() — a
     * migration must not depend on the present-day registry, which could
     * later change/rename entries and silently corrupt this historical grant.
     */
    private function abilities(): array
    {
        return [
            'read-case-tracking',
            'create-case-tracking',
            'update-case-tracking',
            'comment-case-tracking',
            'attach-case-tracking',
        ];
    }

    /**
     * These 5 abilities were previously hardcoded as an unconditional `true`
     * in CaseTicketPolicy — every user got them on their own/assigned cases
     * with no checkbox to control it. A follow-up request asked for that
     * default to be softcoded too. Unlike every other permission migration in
     * this codebase (which grants only to Super Admin, requiring roles to be
     * opted in one at a time), this one grants to EVERY existing role, so it
     * reproduces today's live behavior exactly instead of silently locking
     * Store Manager/Project Manager/etc. out of Case Tracking the moment it
     * runs. Super Admin can uncheck any of these per role afterwards.
     */
    public function up(): void
    {
        $abilities = $this->abilities();

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::all()->each(function (Role $role) use ($abilities) {
            $role->givePermissionTo($abilities);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
