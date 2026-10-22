<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stop Stock Movement: an Adjustment Session can halt the stock movement of its Project / Warehouse while the count is going on (see App\Support\StockHalt).
 * The halt is kept on the session: who halted it and when, and who released it and when. Only a person with the stop-stock-movement permission (and their
 * password) can halt or release it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adjustment_sessions') && ! Schema::hasColumn('adjustment_sessions', 'halt_stock')) {
            Schema::table('adjustment_sessions', function (Blueprint $t) {
                $t->boolean('halt_stock')->default(false)->index();                      // 1 while the stock movement of the warehouse is halted by this (open) session
                $t->unsignedBigInteger('halted_by_user_id')->nullable();
                $t->timestamp('halted_at')->nullable();
                $t->unsignedBigInteger('halt_released_by_user_id')->nullable();          // released by hand, or by whoever ended the session
                $t->timestamp('halt_released_at')->nullable();
            });
        }

        $perm = Permission::query()->firstOrCreate(['name' => 'stop-stock-movement', 'guard_name' => 'web']);
        $super = Role::where('name', 'Super Admin')->first();
        if ($super) {
            $super->givePermissionTo($perm);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('adjustment_sessions', 'halt_stock')) {
            Schema::table('adjustment_sessions', fn (Blueprint $t) => $t->dropColumn(['halt_stock', 'halted_by_user_id', 'halted_at', 'halt_released_by_user_id', 'halt_released_at']));
        }
        Permission::where('name', 'stop-stock-movement')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
