<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'read-fund-allocations', 'create-fund-allocations', 'update-fund-allocations', 'delete-fund-allocations',
        'approve-fund-allocations', 'pay-fund-allocations', 'print-fund-allocations', 'export-fund-allocations',
        'manage-fund-allocation-settings', 'read-fund-allocation-reports',
    ];

    // Super Admin and Admin get everything. Two ready-made roles for the people who normally do the work:
    //   Funds Approver : reviews and approves a sheet (and chooses who pays it)
    //   Funds Payer    : executes an approved sheet - ticks each payment off, links the payment vouchers
    public function up(): void
    {
        $perms = collect(self::PERMISSIONS)->mapWithKeys(fn ($n) => [$n => Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web'])]);

        foreach (Role::whereIn('name', ['Super Admin', 'Admin'])->get() as $role) {
            $role->givePermissionTo($perms->values()->all());
        }

        $accountId = optional(Role::where('name', 'Super Admin')->first())->account_id ?? 1;

        $approver = Role::query()->firstOrCreate(['name' => 'Funds Approver', 'guard_name' => 'web', 'account_id' => $accountId]);
        $approver->givePermissionTo($perms->only(['read-fund-allocations', 'approve-fund-allocations', 'print-fund-allocations', 'export-fund-allocations', 'read-fund-allocation-reports'])->values()->all());

        $payer = Role::query()->firstOrCreate(['name' => 'Funds Payer', 'guard_name' => 'web', 'account_id' => $accountId]);
        $payer->givePermissionTo($perms->only(['read-fund-allocations', 'pay-fund-allocations', 'print-fund-allocations'])->values()->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->whereIn('name', ['Funds Approver', 'Funds Payer'])->forceDelete();
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
