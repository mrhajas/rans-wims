<?php

use App\Services\FeatureManager;
use App\Support\RoleBlueprints;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Travel Packages: a ready-made travel (for example Unguja to Pemba) with what it costs for ONE person: ticket, accommodation, per diem / expenses and inside
 * transport. A person chooses a package, says who travels and when, and a Travel Request is made from it (every travel now starts as a Travel Request that
 * waits for approval). Each package keeps an audit history: every change of its amounts and rates, and every request made from it, with the date.
 * The waiting status of a travel is now called "Travel Request" (it was "Pending Approval").
 */
return new class extends Migration
{
    private const PERMS = ['read-travel-packages', 'create-travel-packages', 'update-travel-packages', 'delete-travel-packages', 'use-travel-packages', 'audit-travel-packages'];

    public function up(): void
    {
        if (! Schema::hasTable('travel_packages')) {
            Schema::create('travel_packages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('name', 150);
                $t->string('trip_type', 10)->default('return');                    // return | one_way
                $t->string('from_place', 150)->nullable();
                $t->string('from_country', 100)->nullable();
                $t->string('to_place', 150);
                $t->string('to_country', 100)->nullable();
                $t->string('currency', 3)->default('TZS');
                $t->decimal('exchange_rate', 15, 6)->default(1);                   // TZS per one unit of the currency
                $t->decimal('ticket_amount', 15, 2)->default(0);                   // all amounts are for ONE person
                $t->decimal('accommodation_amount', 15, 2)->default(0);
                $t->decimal('per_diem_amount', 15, 2)->default(0);                 // expenses / per diem
                $t->decimal('transport_amount', 15, 2)->default(0);                // inside transport
                $t->unsignedBigInteger('ticket_agent_contact_id')->nullable();
                $t->text('notes')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['name'], 'travel_packages_name_idx');
            });
        }
        if (! Schema::hasTable('travel_package_audits')) {
            Schema::create('travel_package_audits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('travel_package_id')->index();
                $t->string('action', 24);                                           // created | updated | activated | deactivated | requested | deleted
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('user_name', 190)->nullable();
                $t->string('name', 150)->nullable();                                // the package as it was at that moment
                $t->string('route', 320)->nullable();
                $t->string('currency', 3)->nullable();
                $t->decimal('exchange_rate', 15, 6)->nullable();
                $t->decimal('ticket_amount', 15, 2)->nullable();
                $t->decimal('accommodation_amount', 15, 2)->nullable();
                $t->decimal('per_diem_amount', 15, 2)->nullable();
                $t->decimal('transport_amount', 15, 2)->nullable();
                $t->decimal('total_per_person', 15, 2)->nullable();
                $t->boolean('is_active')->nullable();
                $t->json('changes')->nullable();                                    // field => [old, new]
                $t->json('details')->nullable();                                    // a request: the travel, the people, the total
                $t->string('ip', 64)->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
            });
        }
        if (Schema::hasTable('travels')) {
            if (! Schema::hasColumn('travels', 'travel_package_id')) {
                Schema::table('travels', function (Blueprint $t) {
                    $t->unsignedBigInteger('travel_package_id')->nullable()->index()->after('status');
                    $t->string('package_name', 150)->nullable()->after('travel_package_id');
                });
            }
            // a travel that waits for approval is a Travel Request
            DB::table('travels')->where('status', 'Pending Approval')->update(['status' => 'Travel Request']);
            try {
                DB::statement("ALTER TABLE travels ALTER COLUMN status SET DEFAULT 'Travel Request'");
            } catch (\Throwable) {
                // the default is only a fallback: the application always sets the status
            }
        }

        if (Schema::hasTable('permissions')) {
            $perms = collect(self::PERMS)->map(fn ($n) => Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']));
            Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($perms->all()));
            // the person who prepares travels: records them, uses the packages and sees their amounts, never approves
            $officer = Role::query()->where('name', 'Travel Officer')->where('guard_name', 'web')->first() ?: Role::create(['name' => 'Travel Officer', 'guard_name' => 'web', 'account_id' => 1]);
            $want = array_merge(RoleBlueprints::EVERYONE, ['read-travels', 'create-travels', 'update-travels', 'print-travels', 'read-travel-agents', 'read-travel-packages', 'use-travel-packages', 'audit-travel-packages']);
            $officer->givePermissionTo(Permission::query()->where('guard_name', 'web')->whereIn('name', $want)->get()->all());
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (Schema::hasTable('system_features')) {
            $f = SystemFeatureRegistry::features()['travel_packages'] ?? null;
            if ($f) {
                $exists = DB::table('system_features')->where('key', 'travel_packages')->exists();
                DB::table('system_features')->updateOrInsert(['key' => 'travel_packages'], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
                DB::table('system_features')->where('key', 'travel_packages')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
                FeatureManager::flushCache();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_package_audits');
        Schema::dropIfExists('travel_packages');
        if (Schema::hasColumn('travels', 'travel_package_id')) {
            Schema::table('travels', fn (Blueprint $t) => $t->dropColumn(['travel_package_id', 'package_name']));
        }
        DB::table('travels')->where('status', 'Travel Request')->update(['status' => 'Pending Approval']);
        Permission::query()->whereIn('name', self::PERMS)->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'travel_packages')->delete();
            FeatureManager::flushCache();
        }
    }
};
