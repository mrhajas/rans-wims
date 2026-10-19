<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Salary is sensitive financial data — new permissions default to Super
 * Admin only (matching this session's convention for other new sensitive
 * modules), other roles opt in via the Roles UI.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return collect(PermissionRegistry::modules())
            ->only(['salary_periods', 'salary_vouchers', 'salary_advances', 'salary_loans', 'salary_analytics', 'salary_reports', 'salary_settings'])
            ->flatMap(fn ($module) => array_keys($module['abilities']))
            ->values()
            ->all();
    }

    public function up(): void
    {
        // A database that has never opened the Roles screen has no rows for these abilities
        // yet, and givePermissionTo() throws on a name it cannot find, so create them first.
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
        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->revokePermissionTo($this->abilities());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
