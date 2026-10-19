<?php

use App\Models\Role;
use App\Services\FeatureManager;
use App\Services\Weather\RuleDefaults;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMS Weather & Site Risk:
 *  1. tables: weather_snapshots (cache of provider answers, also the forecast history), weather_observations (weather seen on site),
 *     weather_risk_rules (editable thresholds), weather_warnings (advisory warnings and their acknowledgements);
 *  2. the five starting rules for every account (flagged "starting values, needs review" until a person reviews them);
 *  3. its abilities (from PermissionRegistry) and who gets them:
 *       read-weather, record-weather-observations     -> every role that already has read-site-diary or read-work-packages
 *       acknowledge-weather-warnings                   -> every role that has read-site-diary AND update-work-packages AND update-warehouses
 *       manage-weather-locations / -rules / -settings and print-weather-reports -> nobody (Super Admin passes every check)
 *  4. its rows in Global Feature Control (parent + five children, read from SystemFeatureRegistry).
 * Safe to run twice. Projects keep using warehouses.latitude / warehouses.longitude for their location: no new location table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('weather_snapshots')) {
            Schema::create('weather_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->string('location_key', 24);
                $table->decimal('latitude', 8, 2);
                $table->decimal('longitude', 8, 2);
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->string('provider', 40);
                $table->string('kind', 12); // current | forecast | alerts
                $table->longText('payload')->nullable();
                $table->string('status', 12)->default('ok'); // ok | failed
                $table->text('error')->nullable();
                $table->string('trigger', 12)->default('schedule'); // schedule | view | manual | test
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->dateTime('fetched_at');
                $table->dateTime('expires_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'location_key', 'kind', 'fetched_at'], 'wx_snap_lookup');
            });
        }

        if (! Schema::hasTable('weather_observations')) {
            Schema::create('weather_observations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->index();
                $table->dateTime('observed_at')->index();
                $table->string('conditions', 30);
                $table->string('rainfall_level', 20)->nullable();
                $table->decimal('rainfall_mm', 6, 1)->nullable();
                $table->decimal('temperature_c', 4, 1)->nullable();
                $table->string('wind_level', 20)->nullable();
                $table->decimal('wind_kmh', 5, 1)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('work_package_id')->nullable();
                $table->unsignedBigInteger('site_diary_id')->nullable()->index();
                $table->date('diary_date')->nullable()->index();
                $table->unsignedBigInteger('recorded_by');
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('weather_risk_rules')) {
            Schema::create('weather_risk_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('activity_key', 40);
                $table->string('label', 120);
                $table->json('keywords')->nullable();
                $table->decimal('max_wind_kmh', 5, 1)->nullable();
                $table->decimal('max_gust_kmh', 5, 1)->nullable();
                $table->unsignedSmallInteger('max_rain_probability_pct')->nullable();
                $table->decimal('max_precip_mm_per_hour', 5, 1)->nullable();
                $table->decimal('max_precip_mm_per_day', 6, 1)->nullable();
                $table->decimal('min_temp_c', 4, 1)->nullable();
                $table->decimal('max_temp_c', 4, 1)->nullable();
                $table->unsignedSmallInteger('lookahead_hours')->default(24);
                $table->string('severity', 10)->default('caution'); // caution | warning
                $table->boolean('is_active')->default(true);
                $table->boolean('is_builtin')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'activity_key'], 'wx_rule_unique');
            });
        }

        if (! Schema::hasTable('weather_warnings')) {
            Schema::create('weather_warnings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->index();
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->unsignedBigInteger('work_package_id')->nullable();
                $table->string('kind', 20); // forecast_rule | provider_alert
                $table->string('severity', 10)->default('caution');
                $table->string('title', 190);
                $table->text('message');
                $table->json('details')->nullable();
                $table->dateTime('window_start')->nullable();
                $table->dateTime('window_end')->nullable();
                $table->string('source_provider', 40)->nullable();
                $table->unsignedBigInteger('snapshot_id')->nullable();
                $table->string('fingerprint', 64);
                $table->string('status', 14)->default('open')->index(); // open | acknowledged | expired | dismissed
                $table->decimal('peak_value', 8, 2)->nullable();
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->dateTime('acknowledged_at')->nullable();
                $table->text('acknowledge_note')->nullable();
                $table->string('acknowledge_decision', 120)->nullable();
                $table->decimal('acknowledged_peak', 8, 2)->nullable();
                $table->dateTime('raised_at')->nullable();
                $table->dateTime('expired_at')->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'fingerprint'], 'wx_warning_fp');
                $table->index(['warehouse_id', 'status'], 'wx_warning_project');
            });
        }

        // 2. the starting rules
        foreach (RuleDefaults::accountIds() as $accountId) {
            RuleDefaults::ensure($accountId);
        }

        // 3. abilities
        $names = array_keys(PermissionRegistry::modules()['weather_risk']['abilities'] ?? []);
        foreach ($names as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Role::query()->get() as $role) {
            $has = fn (string $p) => $role->permissions->contains('name', $p);
            if ($has('read-site-diary') || $has('read-work-packages')) {
                $role->givePermissionTo(['read-weather', 'record-weather-observations']);
            }
            if ($has('read-site-diary') && $has('update-work-packages') && $has('update-warehouses')) {
                $role->givePermissionTo('acknowledge-weather-warnings');
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 4. Global Feature Control rows
        if (Schema::hasTable('system_features')) {
            $order = (int) DB::table('system_features')->max('sort_order');
            foreach (SystemFeatureRegistry::features() as $key => $f) {
                if (! str_starts_with($key, 'weather_')) {
                    continue;
                }
                $exists = DB::table('system_features')->where('key', $key)->exists();
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null,
                    'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => ++$order, 'created_at' => now(), 'is_enabled' => 1]));
            }
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'like', 'weather\_%')->delete();
            FeatureManager::flushCache();
        }
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['weather_risk']['abilities'] ?? []))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['weather_warnings', 'weather_risk_rules', 'weather_observations', 'weather_snapshots'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
