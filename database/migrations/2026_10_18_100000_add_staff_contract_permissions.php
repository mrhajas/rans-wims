<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff contracts and retirement get their own abilities (shown in the Roles page under "Staff Contracts and Retirement").
 * So nobody loses what they could do, every role that already had a staff ability gets the matching new one:
 * read-staffs -> read contracts, create-staffs -> add, update-staffs -> edit / renew / retire, delete-staffs -> delete, print-staffs -> print.
 * Super Admin gets all. Safe to run again.
 */
return new class extends Migration
{
    private const MAP = [
        'read-staffs' => ['read-staff-contracts'],
        'create-staffs' => ['create-staff-contracts'],
        'update-staffs' => ['update-staff-contracts', 'retire-staffs'],
        'delete-staffs' => ['delete-staff-contracts'],
        'print-staffs' => ['print-staff-contracts'],
    ];

    public function up(): void
    {
        $all = collect(self::MAP)->flatten()->unique()->values();
        foreach ($all as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($all->all()));

        foreach (self::MAP as $old => $new) {
            $perm = Permission::query()->where('name', $old)->where('guard_name', 'web')->first();
            if (! $perm) {
                continue;
            }
            Role::query()->whereHas('permissions', fn ($q) => $q->where('permissions.id', $perm->id))->get()->each(fn ($r) => $r->givePermissionTo($new));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // nothing to undo
    }
};
