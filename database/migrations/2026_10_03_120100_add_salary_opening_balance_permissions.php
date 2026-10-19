<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private function abilities(): array
    {
        return [
            'read-salary-opening-balances',
            'create-salary-opening-balances',
            'update-salary-opening-balances',
            'delete-salary-opening-balances',
        ];
    }

    // New role permissions for Salary Opening Balance. Super Admin gets all of them by default;
    // every other role must be granted them explicitly in Roles & Permissions.
    public function up(): void
    {
        foreach ($this->abilities() as $ability) {
            Permission::query()->firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo($this->abilities());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
