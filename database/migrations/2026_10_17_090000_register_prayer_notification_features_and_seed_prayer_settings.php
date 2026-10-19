<?php

use App\Models\PrayerAdhan;
use App\Models\Role;
use App\Models\Setting;
use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1. Lists the newer modules in Global Feature Control (System Features): Prayer Times, Notification Center, Low Stock Alert and Face Login.
 *    A module that has no row there cannot be seen or switched off on that page.
 * 2. Creates the two abilities that were only defined in code (Notification Center and Low Stock Alert) so they can be given to roles.
 * 3. Gives a system that has no Prayer Times settings yet the settings from the development copy (switched ON: Dar es Salaam, MWL / Shafi'i,
 *    alert 10 minutes before, notice + adhan with 3 minutes left, lock at the prayer time for 15 minutes, everybody except Dr Hajas, the "MuftiMenk" adhan).
 *    Settings already saved on a system are never touched. Safe to run again.
 */
return new class extends Migration
{
    private const FEATURES = ['prayer_times', 'notification_center', 'low_stock_alert', 'face_login'];

    private const ADHAN_FILE = 'audio/adhan/custom/zIfqxwYIpo4nn8TXhRmAP02k.mp3';

    public function up(): void
    {
        // 1. features
        $features = SystemFeatureRegistry::features();
        $order = (int) DB::table('system_features')->max('sort_order');
        foreach (self::FEATURES as $key) {
            if (! isset($features[$key]) || DB::table('system_features')->where('key', $key)->exists()) {
                continue;
            }
            $f = $features[$key];
            DB::table('system_features')->insert([
                'key' => $key, 'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                'is_enabled' => true, 'sort_order' => ++$order, 'route_prefixes' => json_encode($f['route_prefixes']),
                'depends_on' => $f['depends_on'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Cache::forget(FeatureManager::CACHE_KEY);
        Cache::forget(FeatureManager::CACHE_KEY.'.routes');

        // 2. abilities (Super Admin passes every check anyway; other roles can be given them in the Roles page)
        foreach (['manage-notification-center', 'manage-low-stock-alerts'] as $name) {
            $p = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            Role::query()->where('name', 'Super Admin')->get()->each(fn ($role) => $role->givePermissionTo($p));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 3. Prayer Times settings
        if (! Schema::hasTable('settings') || DB::table('settings')->where('tec_key', 'prayer_config')->exists()) {
            return;
        }
        $account = (int) (DB::table('settings')->value('account_id') ?: 1);
        $adhan = 'sabah';
        if (Schema::hasTable('prayer_adhans') && is_file(public_path(self::ADHAN_FILE))) {
            $row = PrayerAdhan::query()->where('file', self::ADHAN_FILE)->first()
                ?? PrayerAdhan::query()->create(['name' => 'MuftiMenk', 'file' => self::ADHAN_FILE, 'original_name' => 'MuftiMenk.mp3', 'mime' => 'audio/mpeg', 'size' => (int) filesize(public_path(self::ADHAN_FILE))]);
            $adhan = $row->key();
        }
        $config = [
            'enabled' => true, 'city' => 'Zanzibar', 'lat' => -6.1659, 'lng' => 39.2026, 'elevation' => 0, 'timezone' => 'Africa/Dar_es_Salaam',
            'method' => 'MWL', 'asr' => 1,
            'adjust' => ['fajr' => 0, 'sunrise' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0],
            'prayers' => ['fajr' => true, 'dhuhr' => true, 'asr' => true, 'maghrib' => true, 'isha' => true],
            'alert_before_min' => 10, 'notice_before_min' => 3, 'lock_start_after_min' => 0, 'lock_minutes' => 15,
            'all_users' => true, 'user_ids' => [], 'excluded_user_ids' => [], 'exempt_super_admins' => false,
            'adhan_enabled' => true, 'adhan' => $adhan, 'adhan_volume' => 70, 'show_in_topbar' => true, 'show_on_dashboard' => true,
        ];
        Setting::updateOrCreate(['tec_key' => 'prayer_config', 'account_id' => $account], ['tec_value' => json_encode($config)]);
    }

    public function down(): void
    {
        // nothing to undo: the features, abilities and settings are harmless to keep
    }
};
