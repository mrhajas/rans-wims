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
 * External Stock: stock that belongs to External Personnel (contacts in that category) but is kept in our warehouses. They take some out (a withdrawal:
 * who, driver, vehicle, mobile are recorded), bring more in (a deposit) and may already have stock when the module starts (opening stock). Every movement
 * is approval based, and an approved one can get a Gate Pass, a Delivery Note and a Transport Route. This stock is theirs, so it never changes the
 * company's own stock figures: it has its own ledger (approved deposits and opening stock minus approved withdrawals).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('external_stock_movements')) {
            Schema::create('external_stock_movements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->string('kind', 12)->index();                      // withdrawal | deposit | opening
                $t->date('date')->index();
                $t->unsignedBigInteger('contact_id')->index();        // the External Personnel contact
                $t->unsignedBigInteger('warehouse_id')->index();
                $t->string('status', 24)->default('Pending Approval')->index(); // Pending Approval | Approved | Rejected | Cancelled
                $t->text('purpose')->nullable();
                // who collects / delivers, and how: recorded on every withdrawal
                $t->string('collector_name')->nullable();
                $t->string('collector_phone', 60)->nullable();
                $t->string('collector_id_no', 80)->nullable();
                $t->unsignedBigInteger('driver_contact_id')->nullable();
                $t->string('driver_name')->nullable();
                $t->string('driver_phone', 60)->nullable();
                $t->unsignedBigInteger('asset_id')->nullable();
                $t->string('vehicle_plate', 60)->nullable();
                $t->string('vehicle_details')->nullable();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->unsignedBigInteger('decided_by_user_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision_remarks')->nullable();
                $t->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->text('cancel_reason')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['contact_id', 'warehouse_id', 'status'], 'ext_stock_balance_idx');
            });
        }
        if (! Schema::hasTable('external_stock_items')) {
            Schema::create('external_stock_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('movement_id')->index();
                $t->unsignedBigInteger('item_id')->index();
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->decimal('quantity', 15, 4);
                $t->string('note')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (Schema::hasTable('transport_records') && ! Schema::hasColumn('transport_records', 'external_movement_id')) {
            Schema::table('transport_records', fn (Blueprint $t) => $t->unsignedBigInteger('external_movement_id')->nullable()->after('transfer_id')->index());
        }

        $names = array_keys(PermissionRegistry::modules()['external_stock']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (['external_stock', 'external_stock_reports'] as $key) {
                $f = SystemFeatureRegistry::features()[$key] ?? null;
                if (! $f) {
                    continue;
                }
                $exists = DB::table('system_features')->where('key', $key)->exists();
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
                DB::table('system_features')->where('key', $key)->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            }
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_records', 'external_movement_id')) {
            Schema::table('transport_records', fn (Blueprint $t) => $t->dropColumn('external_movement_id'));
        }
        Schema::dropIfExists('external_stock_items');
        Schema::dropIfExists('external_stock_movements');
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['external_stock']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->whereIn('key', ['external_stock', 'external_stock_reports'])->delete();
            FeatureManager::flushCache();
        }
    }
};
