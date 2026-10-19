<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accounts Tracking: money in, money out, transfers and adjustments each get their own create / approve / post ability (all of them need approval).
 * The three general abilities they replace are removed; a role that held them is given the new ones that mean the same.
 */
return new class extends Migration
{
    private const OLD = ['approve-acct-transactions' => 'approve', 'reject-acct-transactions' => 'approve', 'post-acct-transactions' => 'post'];

    private const KINDS = ['inflows', 'outflows', 'transfers', 'adjustments'];

    public function up(): void
    {
        $names = array_keys(PermissionRegistry::modules()['accounts_tracking']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        foreach (self::OLD as $old => $action) {
            $perm = Permission::query()->where('name', $old)->first();
            if (! $perm) {
                continue;
            }
            $new = Permission::query()->whereIn('name', array_map(fn ($k) => $action . '-acct-' . $k, self::KINDS))->get();
            foreach (Role::query()->get() as $role) {
                if ($role->hasPermissionTo($perm)) {
                    $role->givePermissionTo($new->all());
                }
            }
            DB::table('model_has_permissions')->where('permission_id', $perm->id)->get()->each(function ($row) use ($new) {
                foreach ($new as $p) {
                    DB::table('model_has_permissions')->updateOrInsert(['permission_id' => $p->id, 'model_id' => $row->model_id, 'model_type' => $row->model_type]);
                }
            });
            $perm->delete();
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $new = [];
        foreach (['approve', 'post'] as $a) {
            foreach (self::KINDS as $k) {
                $new[] = $a . '-acct-' . $k;
            }
        }
        Permission::query()->whereIn('name', $new)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
