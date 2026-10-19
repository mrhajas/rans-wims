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
 * Travels: the travels of staff and users (one record, one or more travellers), where they go, the flight, the ticket and the visa, who issued them
 * (contacts in the Ticket Agent / Visa Agent categories) and what each cost. A travel is approval based and may be in the future. What an agent is owed
 * lives in this module: an opening balance (owed before the module started), the approved travels, and the payments made, kept in separate rows
 * so a balance is always the sum of its history. Payments to agents go only through Payment Vouchers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('travels')) {
            Schema::create('travels', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->string('status', 24)->default('Pending Approval')->index();   // Pending Approval | Approved | Rejected | Cancelled
                $t->string('trip_type', 10)->default('return');                    // one_way | return
                $t->string('travel_type', 60)->nullable();                         // Business, Training, Medical...
                $t->text('purpose')->nullable();
                $t->string('from_place', 150)->nullable();
                $t->string('from_country', 100)->nullable();
                $t->string('to_place', 150);                                       // where they are going (city / place)
                $t->string('to_country', 100)->nullable();
                $t->string('scope', 14)->default('local')->index();                // local | international (the country they go to against the home country setting)
                $t->date('depart_date')->index();                                  // may be in the future
                $t->date('return_date')->nullable();
                $t->date('booking_date')->nullable()->index();                     // the day the ticket was bought: what the agent is owed from
                $t->string('airline', 120)->nullable();
                $t->string('flight_number', 40)->nullable();
                $t->string('return_airline', 120)->nullable();
                $t->string('return_flight_number', 40)->nullable();
                $t->string('booking_reference', 60)->nullable();                   // PNR
                $t->unsignedBigInteger('ticket_agent_contact_id')->nullable()->index(); // who issued the ticket
                $t->string('currency', 3)->default('TZS');
                $t->decimal('ticket_amount', 15, 2)->default(0);                   // the sum of the travellers
                $t->boolean('has_visa')->default(false);
                $t->string('visa_type', 80)->nullable();
                $t->string('visa_country', 120)->nullable();
                $t->string('visa_reference', 80)->nullable();
                $t->unsignedBigInteger('visa_agent_contact_id')->nullable()->index();  // who issued the visa
                $t->decimal('visa_amount', 15, 2)->default(0);                     // the sum of the travellers
                $t->decimal('total_amount', 15, 2)->default(0);                    // ticket + visa
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('user_id')->nullable()->index();            // recorded by
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
                $t->index(['status', 'depart_date'], 'travels_status_depart_idx');
            });
        }
        if (! Schema::hasTable('travel_travellers')) {
            Schema::create('travel_travellers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('travel_id')->index();
                $t->string('traveller_type', 10)->default('staff');                // staff | user | other
                $t->unsignedBigInteger('staff_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('name', 190);                                           // kept as typed / as it was on the day
                $t->string('phone', 60)->nullable();
                $t->string('ticket_number', 60)->nullable();
                $t->decimal('ticket_amount', 15, 2)->default(0);
                $t->decimal('visa_amount', 15, 2)->default(0);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('travel_agent_openings')) {
            Schema::create('travel_agent_openings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedBigInteger('contact_id')->index();
                $t->string('currency', 3)->default('TZS');
                $t->decimal('amount', 15, 2);                                      // what the agent was owed before: positive = we owe them
                $t->date('as_of_date');                                            // travels booked before this day are already inside it
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['contact_id', 'currency'], 'travel_opening_agent_idx');
            });
        }
        if (! Schema::hasTable('travel_agent_payments')) {
            // A payment to an agent is made ONLY through a Payment Voucher (made from the agent's balance, approved in the Payment Vouchers module).
            // This row is the link: it reserves the amount while the voucher is waiting ("Pending Voucher"), counts against the balance once the voucher is paid ("Paid"),
            // and stays on record as "Released" when the voucher is rejected, cancelled or deleted.
            Schema::create('travel_agent_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->unsignedBigInteger('contact_id')->index();
                $t->unsignedBigInteger('travel_id')->nullable()->index();         // optional: the travel it is paid for
                $t->string('currency', 3)->default('TZS');
                $t->decimal('amount', 15, 2);                                      // in the agent's currency: this is what reduces the balance
                $t->decimal('exchange_rate', 15, 6)->default(1);                   // 1 for TZS; for a foreign currency, how many TZS one unit is
                $t->decimal('voucher_amount', 15, 2);                              // the amount of the Payment Voucher (TZS)
                $t->string('status', 16)->default('Pending Voucher')->index();    // Pending Voucher | Paid | Released
                $t->unsignedBigInteger('payment_voucher_id')->nullable()->index();
                $t->date('requested_on');
                $t->date('paid_on')->nullable()->index();
                $t->string('method', 100)->nullable();
                $t->string('payment_reference', 190)->nullable();                  // the voucher's payment reference, or its number
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();                     // who asked for the payment
                $t->unsignedBigInteger('paid_by_user_id')->nullable();
                $t->timestamp('released_at')->nullable();
                $t->text('release_reason')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['contact_id', 'currency', 'status'], 'travel_pay_agent_idx');
            });
        }
        if (Schema::hasTable('payment_voucher_items') && ! Schema::hasColumn('payment_voucher_items', 'travel_agent_payment_id')) {
            Schema::table('payment_voucher_items', fn (Blueprint $t) => $t->unsignedBigInteger('travel_agent_payment_id')->nullable()->after('transport_loading_payment_id')->index());
        }
        if (Schema::hasTable('payment_voucher_types')) {
            DB::table('payment_voucher_types')->updateOrInsert(['text' => 'Travel Agent'], ['updated_at' => now(), 'created_at' => now()]);
        }

        $names = array_keys(PermissionRegistry::modules()['travels']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (['travels', 'travel_agents', 'travel_reports'] as $key) {
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
        if (Schema::hasColumn('payment_voucher_items', 'travel_agent_payment_id')) {
            Schema::table('payment_voucher_items', fn (Blueprint $t) => $t->dropColumn('travel_agent_payment_id'));
        }
        Schema::dropIfExists('travel_agent_payments');
        Schema::dropIfExists('travel_agent_openings');
        Schema::dropIfExists('travel_travellers');
        Schema::dropIfExists('travels');
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['travels']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->whereIn('key', ['travels', 'travel_agents', 'travel_reports'])->delete();
            FeatureManager::flushCache();
        }
    }
};
