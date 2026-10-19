<?php

use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Responsive Audit: the table of audit runs and their findings, its four abilities (Super Admin only until granted on the Roles screen)
 * and its row in Global Feature Control. Safe to run twice.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ui_audit_runs')) {
            Schema::create('ui_audit_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->unsignedSmallInteger('width');
                $table->unsignedInteger('pages_scanned')->default(0);
                $table->unsignedInteger('findings_total')->default(0);
                $table->json('summary')->nullable();
                $table->string('source', 20)->default('browser');
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('ui_audit_findings')) {
            Schema::create('ui_audit_findings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->string('page', 190)->index();
                $table->string('category', 24)->index();
                $table->string('selector', 190)->nullable();
                $table->string('label', 120)->nullable();
                $table->string('detail', 255)->nullable();
                $table->timestamps();
            });
        }

        foreach (array_keys(PermissionRegistry::modules()['responsive_audit']['abilities'] ?? []) as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features') && ($f = SystemFeatureRegistry::features()['responsive_audit'] ?? null)) {
            if (! DB::table('system_features')->where('key', 'responsive_audit')->exists()) {
                DB::table('system_features')->insert([
                    'key' => 'responsive_audit', 'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'is_enabled' => 1, 'depends_on' => $f['depends_on'] ?? null, 'route_prefixes' => json_encode($f['route_prefixes']),
                    'sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        // the audit history is kept
    }
};
