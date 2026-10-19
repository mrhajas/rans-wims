<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // approve-transfers / cancel-transfers decide who can release (or stop) a transfer leaving a main
    // warehouse. Super Admin and Admin get both; a dedicated "Transfer Approver" role is created holding
    // just what an approver needs (view, approve, cancel, print) so it can be assigned on its own.
    public function up(): void
    {
        $approve = Permission::query()->firstOrCreate(['name' => 'approve-transfers', 'guard_name' => 'web']);
        $cancel = Permission::query()->firstOrCreate(['name' => 'cancel-transfers', 'guard_name' => 'web']);

        foreach (Role::whereIn('name', ['Super Admin', 'Admin'])->get() as $role) {
            $role->givePermissionTo([$approve, $cancel]);
        }

        $accountId = optional(Role::where('name', 'Super Admin')->first())->account_id ?? 1;
        $approver = Role::query()->firstOrCreate(['name' => 'Transfer Approver', 'guard_name' => 'web', 'account_id' => $accountId]);

        $grant = [$approve, $cancel];
        foreach (['read-transfers', 'print-transfers'] as $name) {
            $grant[] = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $approver->givePermissionTo($grant);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', 'Transfer Approver')->forceDelete();
        Permission::query()->whereIn('name', ['approve-transfers', 'cancel-transfers'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
