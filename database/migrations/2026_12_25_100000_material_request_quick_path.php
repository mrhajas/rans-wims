<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Material Requests: the Quick path. A request can be raised as a Quick request (a shorter form, its own approver, and no procurement procedures
 * or delivery check on the way to Cleared). Two new permissions on the Roles screen, given to nobody but Super Admin until they are assigned:
 * quick-material-requests (raise one) and quick-approve-material-requests (approve / reject one).
 */
return new class extends Migration
{
    private const PERMISSIONS = ['quick-material-requests', 'quick-approve-material-requests'];

    public function up(): void
    {
        if (Schema::hasTable('material_requests') && ! Schema::hasColumn('material_requests', 'quick')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->boolean('quick')->default(false)->after('status')->index();
            });
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            $super = Role::query()->where('name', 'Super Admin')->first();
            foreach (self::PERMISSIONS as $name) {
                $p = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($super) {
                    $super->givePermissionTo($p);
                }
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        if (Schema::hasColumn('material_requests', 'quick')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->dropIndex(['quick']);
                $table->dropColumn('quick');
            });
        }
    }
};
