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
 * QR codes: one table for every record's scan-to-open code (the record tables are NOT touched), its two abilities and its Global Feature
 * Control row. A code is a random token; the scan address carries no database id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qr_codes')) {
            Schema::create('qr_codes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('type', 40);
                $table->unsignedBigInteger('record_id');
                $table->string('token', 40)->unique();
                $table->unsignedInteger('scans_count')->default(0);
                $table->timestamp('last_scanned_at')->nullable();
                $table->unsignedBigInteger('last_scanned_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->timestamps();
                $table->index(['type', 'record_id', 'revoked_at']);
            });
        }

        $names = array_keys(PermissionRegistry::modules()['qr_codes']['abilities'] ?? []);
        foreach ($names as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $perms = Permission::query()->whereIn('name', $names)->get();
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($perms->all()));
        // whoever may already print documents may print / download a QR label too
        $print = Permission::query()->where('name', 'print-qr-codes')->first();
        if ($print) {
            $roleIds = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', 'like', 'print-%')->where('permissions.name', '<>', 'print-qr-codes')->distinct()->pluck('role_has_permissions.role_id');
            Role::query()->whereIn('id', $roleIds)->get()->each(fn ($r) => $r->givePermissionTo($print));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            $f = SystemFeatureRegistry::features()['qr_codes'] ?? null;
            if ($f) {
                DB::table('system_features')->updateOrInsert(['key' => 'qr_codes'], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null,
                    'sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'updated_at' => now(), 'created_at' => now(),
                ]);
                DB::table('system_features')->where('key', 'qr_codes')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
                FeatureManager::flushCache();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['qr_codes']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'qr_codes')->delete();
            FeatureManager::flushCache();
        }
    }
};
