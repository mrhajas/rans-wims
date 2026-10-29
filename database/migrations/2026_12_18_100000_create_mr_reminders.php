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
 * Material Request Reminders: a reminder sent about an approved request that has not reached its final status (with a note and files), and one delivery row per person and
 * channel (email, SMS, app alert) saying who was told what and whether it went. Everybody who can see Material Requests can send reminders unless a Super Admin takes the permission away.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mr_reminders')) {
            Schema::create('mr_reminders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedBigInteger('material_request_id')->index();
                $t->string('reference', 60)->index();                    // a snapshot: the request keeps its reference, but the reminder must read the same later
                $t->string('status_at_send', 40);
                $t->text('note')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('mr_reminder_deliveries')) {
            Schema::create('mr_reminder_deliveries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('reminder_id')->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('name', 190);
                $t->string('channel', 8);                                // email | sms | app
                $t->string('address', 190)->nullable();
                $t->string('status', 10)->default('Sent')->index();      // Sent | Failed | Skipped
                $t->text('error')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        $names = array_keys(PermissionRegistry::modules()['mr_reminders']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        $perms = Permission::query()->whereIn('name', $names)->get();
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($perms->all()));
        // by default whoever can open Material Requests can send and see the reminders (Super Admin alone decides who may change the recipients)
        $everyday = $perms->whereIn('name', ['read-mr-reminders', 'send-mr-reminders', 'print-mr-reminders'])->all();
        Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'read-material-requests'))->get()->each(fn ($r) => $r->givePermissionTo($everyday));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (SystemFeatureRegistry::features() as $key => $f) {
                if ($key !== 'mr_reminders') {
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
        Schema::dropIfExists('mr_reminder_deliveries');
        Schema::dropIfExists('mr_reminders');
    }
};
