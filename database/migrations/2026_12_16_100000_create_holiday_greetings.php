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
 * Holiday Greetings: the public holidays of each year (computed and then editable), an address book of the people who receive a greeting (imported from
 * users, staff and contacts, or typed in), and one delivery row per person and channel of the greetings sent for a holiday. The module is deliberately
 * not connected to the rest of WIMS: it only reads users, staff and contacts when somebody presses Import.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holiday_days')) {
            Schema::create('holiday_days', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedSmallInteger('year')->index();
                $t->string('key', 60);                                   // a stable slug (christmas, eid_fitr...); custom holidays get custom-<n>
                $t->string('name', 160);
                $t->string('theme', 40)->default('generic');             // the look and the words of the WIMS template
                $t->string('kind', 16)->default('public');               // public | religious | observance | custom
                $t->date('date')->index();
                $t->boolean('date_confirmed')->default(true);            // false for the holidays that follow the moon: the date is an estimate until a Super Admin confirms it
                $t->boolean('is_enabled')->default(true);                // switched off = no reminder and no greetings
                $t->boolean('force_include')->default(false);            // a holiday on a Saturday or Sunday does not count unless a Super Admin includes it
                $t->string('email_subject', 190)->nullable();            // the Super Admin's own wording; null = the WIMS template
                $t->string('email_headline', 160)->nullable();
                $t->text('email_body')->nullable();
                $t->text('sms_body')->nullable();
                $t->json('excluded')->nullable();                        // {email:[contact ids], sms:[contact ids]} left out of this holiday only
                $t->timestamp('reminder_sent_at')->nullable();
                $t->timestamp('send_started_at')->nullable();
                $t->unsignedBigInteger('sent_by_user_id')->nullable();
                $t->unsignedBigInteger('edited_by_user_id')->nullable();
                $t->timestamp('edited_at')->nullable();
                $t->text('note')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['account_id', 'year', 'key'], 'holiday_days_unique');
            });
        }
        if (! Schema::hasTable('holiday_contacts')) {
            Schema::create('holiday_contacts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('title', 40)->nullable();
                $t->string('name', 190)->index();
                $t->string('email', 190)->nullable()->index();           // lower case: how a repeated address is recognised
                $t->string('mobile', 20)->nullable()->index();           // always +255XXXXXXXXX
                $t->string('source', 12)->default('manual');             // user | staff | contact | manual
                $t->unsignedBigInteger('source_id')->nullable();
                $t->string('group_label', 80)->nullable();               // the contact category, or Users / Staff
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('holiday_deliveries')) {
            Schema::create('holiday_deliveries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('holiday_id')->index();
                $t->unsignedBigInteger('contact_id')->nullable()->index();
                $t->string('channel', 8);                                // email | sms
                $t->string('address', 190);
                $t->string('title', 40)->nullable();
                $t->string('name', 190);
                $t->string('status', 12)->default('Queued')->index();    // Queued | Sending | Sent | Failed
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->text('error')->nullable();
                $t->string('provider_message_id', 120)->nullable();
                $t->unsignedBigInteger('sent_by_user_id')->nullable();
                $t->timestamp('queued_at')->nullable();
                $t->timestamp('last_attempt_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['holiday_id', 'channel', 'address'], 'holiday_deliveries_unique');
            });
        }

        $names = array_keys(PermissionRegistry::modules()['holiday_greetings']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (SystemFeatureRegistry::features() as $key => $f) {
                if (! str_starts_with($key, 'holiday_')) {
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
        Schema::dropIfExists('holiday_deliveries');
        Schema::dropIfExists('holiday_contacts');
        Schema::dropIfExists('holiday_days');
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['holiday_greetings']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'like', 'holiday\\_%')->delete();
            FeatureManager::flushCache();
        }
    }
};
