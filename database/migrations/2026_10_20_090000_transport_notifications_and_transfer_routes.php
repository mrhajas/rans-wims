<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A Transport Routes trip can be generated straight from a Transfer (transport_records.transfer_id keeps the link both ways),
 * and the Transport Routes module gets its own notification page. Two new abilities, both given to Super Admin only:
 * - generate-transport-route-transfers (Transfers: "Generate Transport Route")
 * - manage-transport-notifications (Transport Routes: who is told what, by app bell / message / email, and when)
 * Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transport_records', 'transfer_id')) {
            Schema::table('transport_records', function (Blueprint $table) {
                $table->unsignedBigInteger('transfer_id')->nullable()->after('trip_number')->index();
            });
        }

        $names = ['generate-transport-route-transfers', 'manage-transport-notifications'];
        foreach ($names as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($names));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_records', 'transfer_id')) {
            Schema::table('transport_records', function (Blueprint $table) {
                $table->dropColumn('transfer_id');
            });
        }
    }
};
