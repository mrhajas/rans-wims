<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Roles with "All warehouses" ticked (roles.all_warehouses): everyone holding such a role is assigned every project / warehouse, and every project / warehouse created later.
 * Warehouse access in WIMS is a list on the person (user_warehouse); this keeps that list complete for these roles.
 */
class AllWarehouseRoles
{
    private static function every(?int $accountId)
    {
        return Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->when($accountId, fn ($q) => $q->where('account_id', $accountId))->pluck('id')->all();
    }

    public static function usersOf(): \Illuminate\Database\Eloquent\Builder
    {
        return User::withoutGlobalScopes()->whereHas('roles', fn ($q) => $q->where('roles.all_warehouses', true));
    }

    /** Gives this person every warehouse when one of their roles has "All warehouses". */
    public static function apply(User $user): void
    {
        try {
            if ($user->roles()->where('roles.all_warehouses', true)->exists()) {
                $user->warehouses()->sync(self::every($user->account_id));
            }
        } catch (\Throwable $e) {
            // the column is not there yet (migration not run): nothing to do
        }
    }

    /** After a role is saved with "All warehouses": everyone who holds it gets every warehouse. */
    public static function syncRoleUsers(Role $role): int
    {
        if (! $role->all_warehouses) {
            return 0;
        }
        $n = 0;
        foreach (User::withoutGlobalScopes()->role($role->name)->get() as $u) {
            $u->warehouses()->sync(self::every($u->account_id));
            $n++;
        }

        return $n;
    }

    /** A new project / warehouse goes to everyone who has an "All warehouses" role. */
    public static function attachNew(Warehouse $warehouse): void
    {
        try {
            $ids = self::usersOf()->when($warehouse->account_id, fn ($q) => $q->where('account_id', $warehouse->account_id))->pluck('users.id');
            if ($ids->isNotEmpty()) {
                $warehouse->users()->syncWithoutDetaching($ids->all());
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not attach the new warehouse to the All warehouses roles', ['warehouse_id' => $warehouse->id, 'error' => $e->getMessage()]);
        }
    }
}
