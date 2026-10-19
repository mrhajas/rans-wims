<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Signatories: the ability to register a signature for another user without waiting for their own confirmation (they are told in the app bell). */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('register-signatories-without-confirmation', 'web');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'register-signatories-without-confirmation')->delete();
    }
};
