<?php

use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Multi Transfer: ONE request, several source warehouses, one destination. It creates an ordinary Transfer per source (all held for approval) and
 * keeps them together under one group. Here: the group table, the link on each transfer, its abilities and its Global Feature Control row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('multi_transfers')) {
            Schema::create('multi_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->string('reference', 50)->unique();
                $table->string('hash', 64)->nullable();
                $table->date('date');
                $table->unsignedBigInteger('to_warehouse_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->text('details')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasColumn('transfers', 'multi_transfer_id')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unsignedBigInteger('multi_transfer_id')->nullable()->after('recurring_id')->index();
            });
        }

        // abilities: Super Admin passes every check; the rest are given on the Roles screen
        foreach (array_keys(PermissionRegistry::modules()['multi_transfers']['abilities'] ?? []) as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $all = Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['multi_transfers']['abilities'] ?? []))->get();
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($all->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            $f = SystemFeatureRegistry::features()['multi_transfers'] ?? null;
            if ($f) {
                DB::table('system_features')->updateOrInsert(['key' => 'multi_transfers'], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null,
                    'sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'updated_at' => now(), 'created_at' => now(),
                ]);
                DB::table('system_features')->where('key', 'multi_transfers')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
                FeatureManager::flushCache();
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transfers', 'multi_transfer_id')) {
            Schema::table('transfers', fn (Blueprint $t) => $t->dropColumn('multi_transfer_id'));
        }
        Schema::dropIfExists('multi_transfers');
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['multi_transfers']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'multi_transfers')->delete();
            FeatureManager::flushCache();
        }
    }
};
