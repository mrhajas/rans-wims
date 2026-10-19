<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const FEATURES = ['archive', 'archive_tree', 'archive_notifications', 'fund_allocations'];

    public function up(): void
    {
        // Who gets told what, and when. One row per event per account; missing rows fall back to the defaults in
        // ArchiveNotificationRule::defaults() (Super Admins only, for now).
        Schema::create('archive_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('event_key', 60);
            $table->boolean('enabled')->default(true);
            $table->boolean('notify_bell')->default(true);
            $table->boolean('send_email')->default(false);
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('exclude_actor')->default(true);
            $table->string('send_time', 5)->nullable();      // HH:MM, scheduled events only
            $table->unsignedSmallInteger('lead_days')->nullable(); // "expiring within N days"
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'event_key']);
        });

        // Appear on System Features (Archive + its two sub-features; Funds Allocation was missing from that page too).
        $features = SystemFeatureRegistry::features();
        $sortOrder = (int) DB::table('system_features')->max('sort_order') + 1;
        foreach (self::FEATURES as $key) {
            $feature = $features[$key];
            DB::table('system_features')->updateOrInsert(['key' => $key], [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => $sortOrder++,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        FeatureManager::flushCache();

        // Super Admin only for now: the permission exists but no role is given it.
        Permission::query()->firstOrCreate(['name' => 'manage-archive-notifications', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_notification_rules');
        DB::table('system_features')->whereIn('key', ['archive', 'archive_tree', 'archive_notifications'])->delete();
        FeatureManager::flushCache();
        Permission::query()->where('name', 'manage-archive-notifications')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
