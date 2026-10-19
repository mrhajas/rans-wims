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
 * QR Scans (the history of every scan, allowed or refused), the QR Activation / QR Scans abilities and the two child features
 * (QR Scanner, QR Scans) in Global Feature Control. Everybody keeps the scanner and their own history; the rest is Super Admin until granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qr_scans')) {
            Schema::create('qr_scans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->unsignedBigInteger('qr_code_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('type', 40)->nullable()->index();
                $table->unsignedBigInteger('record_id')->nullable();
                $table->string('kind', 80)->nullable();
                $table->string('reference', 160)->nullable();
                $table->string('status', 24)->index();   // allowed | no_permission | unavailable | disabled | invalid
                $table->string('reason', 60)->nullable();
                $table->string('via', 12)->default('scanner'); // scanner | link
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        $names = array_keys(PermissionRegistry::modules()['qr_codes']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        $by = Permission::query()->whereIn('name', $names)->get()->keyBy('name');
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($by->all()));
        // everybody keeps the scanner and the history of their own scans
        // straight into the pivot, for EVERY role row (the Role model hides none, but a role added by hand elsewhere must not be missed)
        foreach (DB::table('roles')->where('guard_name', 'web')->pluck('id') as $roleId) {
            foreach (['scan-qr-codes', 'read-qr-scans'] as $n) {
                DB::table('role_has_permissions')->updateOrInsert(['permission_id' => $by[$n]->id, 'role_id' => $roleId], []);
            }
        }
        $printRoles = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', 'read-download-print-logs')->pluck('role_has_permissions.role_id');
        Role::query()->whereIn('id', $printRoles)->get()->each(fn ($r) => $r->givePermissionTo([$by['view-all-qr-scans'], $by['print-qr-scans']]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (['qr_codes', 'qr_scanner', 'qr_scans'] as $key) {
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
        Schema::dropIfExists('qr_scans');
        Permission::query()->whereIn('name', ['scan-qr-codes', 'read-qr-scans', 'view-all-qr-scans', 'print-qr-scans', 'activate-qr-codes'])->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->whereIn('key', ['qr_scanner', 'qr_scans'])->delete();
            FeatureManager::flushCache();
        }
    }
};
