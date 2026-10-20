<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_records') && ! Schema::hasColumn('compliance_records', 'auto_renew')) {
            Schema::table('compliance_records', function (Blueprint $t) {
                $t->boolean('auto_renew')->default(false)->index();                 // the record renews itself on its expiry date
                $t->unsignedInteger('auto_renew_days')->nullable();                 // how many days each automatic renewal lasts (new expiry = expiry + this)
                $t->json('auto_renew_notify_days')->nullable();                     // days before the renewal on which the owners are told, for example [7, 3, 1]
                $t->unsignedBigInteger('auto_renew_set_by')->nullable();            // who set it up
                $t->timestamp('auto_renew_set_at')->nullable();
                $t->unsignedSmallInteger('auto_renewed_count')->default(0);         // how many times it has renewed itself
                $t->timestamp('last_auto_renewed_at')->nullable();
            });
        }

        // Setting up auto renewal is a Super Admin power for now. The permission exists so it can be given to a role later from the Roles screen.
        $perm = Permission::query()->firstOrCreate(['name' => 'manage-compliance-auto-renewal', 'guard_name' => 'web']);
        $super = Role::where('name', 'Super Admin')->first();
        if ($super) {
            $super->givePermissionTo($perm);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('compliance_records', 'auto_renew')) {
            Schema::table('compliance_records', fn (Blueprint $t) => $t->dropColumn(['auto_renew', 'auto_renew_days', 'auto_renew_notify_days', 'auto_renew_set_by', 'auto_renew_set_at', 'auto_renewed_count', 'last_auto_renewed_at']));
        }
        Permission::where('name', 'manage-compliance-auto-renewal')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
