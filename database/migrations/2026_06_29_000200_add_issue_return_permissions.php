<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'read-issue-returns',
        'create-issue-returns',
        'update-issue-returns',
        'delete-issue-returns',
        'return-issue-returns',
        'print-issue-returns',
        'issue-return-reports',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->give('Admin', $this->permissions);
        $this->give('Warehouse Manager', [
            'read-issue-returns',
            'create-issue-returns',
            'update-issue-returns',
            'return-issue-returns',
            'print-issue-returns',
        ]);
        $this->give('Store Manager', [
            'read-issue-returns',
            'create-issue-returns',
            'update-issue-returns',
            'return-issue-returns',
            'print-issue-returns',
        ]);
        $this->give('Site Engineers', [
            'read-issue-returns',
            'return-issue-returns',
        ]);
        $this->give('Site Engineer', [
            'read-issue-returns',
            'return-issue-returns',
        ]);
        $this->give('Engineer', [
            'read-issue-returns',
            'return-issue-returns',
        ]);
        $this->give('Procurement Manager', ['read-issue-returns']);
        $this->give('Procurement Manger', ['read-issue-returns']);
        $this->give('Project Manager', ['read-issue-returns']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roles = [
            'Admin',
            'Warehouse Manager',
            'Store Manager',
            'Site Engineers',
            'Site Engineer',
            'Engineer',
            'Procurement Manager',
            'Procurement Manger',
            'Project Manager',
        ];

        foreach ($roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->revokePermissionTo($this->permissions);
            }
        }

        Permission::query()->whereIn('name', $this->permissions)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function give(string $roleName, array $permissions): void
    {
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
};
