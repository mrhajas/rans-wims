<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Site engineers schedule and run the meetings of their own projects: they get create + edit on top of "write the minutes".
 * They still only WORK on meetings of the projects they are assigned to (they can see the whole schedule). Only adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::query()->where('name', 'Engineers')->get()->each(fn ($r) => $r->givePermissionTo(['create-site-meetings', 'update-site-meetings']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
    }
};
