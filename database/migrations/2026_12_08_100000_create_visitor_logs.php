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
 * Visitor Logs: the visitors who come to the premises (a directory of returning visitors, one log per visit with check-in and check-out), and the letters,
 * packages and documents received at reception. Their lists (purposes, item types, item statuses, departments, visitor categories) are rows in
 * `visitor_options`, so a Super Admin changes them without code. Every change of a visit or an item (check-out, correction, status, hand-over) is kept in
 * `visitor_log_events`; the generic activity log records the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visitor_options')) {
            Schema::create('visitor_options', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('kind', 24);                                   // purpose | item_type | item_status | department | visitor_category
                $t->string('name', 120);
                $t->string('flag', 24)->nullable();                       // purpose: other | item_type: document / package | item_status: initial / ready / handed / closed
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['kind', 'is_active', 'sort_order'], 'visitor_options_kind_idx');
            });
        }
        if (! Schema::hasTable('visitors')) {
            Schema::create('visitors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->string('full_name', 190)->index();
                $t->string('company', 190)->nullable()->index();
                $t->string('phone', 40)->nullable()->index();
                $t->string('phone_key', 20)->nullable()->index();         // the last 9 digits: how a repeated phone number is recognised
                $t->string('email', 190)->nullable();
                $t->string('id_type', 60)->nullable();
                $t->text('id_number')->nullable();                        // encrypted at rest, shown only to people who may see sensitive details
                $t->string('nationality', 100)->nullable();
                $t->text('address')->nullable();
                $t->string('category', 80)->nullable();
                $t->string('vehicle_registration', 40)->nullable();
                $t->text('emergency_notes')->nullable();
                $t->text('remarks')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('deactivated_by_user_id')->nullable();
                $t->timestamp('deactivated_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('visitor_visits')) {
            Schema::create('visitor_visits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->unsignedBigInteger('visitor_id')->index();
                $t->date('visit_date')->index();
                $t->dateTime('check_in_at')->index();
                $t->dateTime('check_out_at')->nullable()->index();
                $t->unsignedInteger('duration_minutes')->nullable();
                $t->string('status', 16)->default('Checked In')->index();   // Checked In | Checked Out | Cancelled
                $t->unsignedBigInteger('host_user_id')->nullable()->index(); // the person being visited
                $t->string('host_name', 190)->nullable();                    // kept as it was on the day
                $t->string('department', 120)->nullable()->index();
                $t->string('purpose', 160)->nullable();                      // kept as it was on the day
                $t->text('purpose_other')->nullable();
                $t->string('appointment_ref', 80)->nullable();
                $t->unsignedSmallInteger('companions')->default(0);
                $t->string('vehicle_registration', 40)->nullable();
                $t->text('remarks')->nullable();
                $t->unsignedBigInteger('registered_by_user_id')->nullable()->index();
                $t->unsignedBigInteger('checked_out_by_user_id')->nullable();
                $t->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->text('cancel_reason')->nullable();
                $t->timestamp('long_stay_alerted_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['status', 'check_in_at'], 'visitor_visits_status_in_idx');
            });
        }
        if (! Schema::hasTable('visitor_incoming_items')) {
            Schema::create('visitor_incoming_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->dateTime('received_at')->index();
                $t->string('item_type', 80)->nullable();
                $t->string('sender_name', 190)->nullable();
                $t->string('sender_company', 190)->nullable();
                $t->string('sender_contact', 190)->nullable();
                $t->string('courier', 120)->nullable();
                $t->string('tracking_number', 120)->nullable();
                $t->unsignedBigInteger('recipient_user_id')->nullable()->index();
                $t->string('recipient_name', 190)->nullable();
                $t->string('department', 120)->nullable();
                $t->string('subject', 255)->nullable();
                $t->boolean('is_confidential')->default(false)->index();
                $t->unsignedSmallInteger('item_count')->default(1);
                $t->string('item_condition', 160)->nullable();
                $t->unsignedBigInteger('received_by_user_id')->nullable()->index();
                $t->unsignedBigInteger('visit_id')->nullable()->index();
                $t->unsignedBigInteger('visitor_id')->nullable()->index();
                $t->string('status', 80)->index();
                $t->string('status_flag', 24)->nullable()->index();           // initial / ready / handed / closed / null (any other open status)
                $t->dateTime('status_changed_at')->nullable();
                $t->string('handed_to', 190)->nullable();                     // the recipient or the authorized collector
                $t->dateTime('handed_at')->nullable();
                $t->unsignedBigInteger('released_by_user_id')->nullable();
                $t->text('handover_remarks')->nullable();
                $t->text('remarks')->nullable();
                $t->timestamp('overdue_alerted_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('visitor_log_events')) {
            Schema::create('visitor_log_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('subject_type', 12);                              // visit | item | visitor
                $t->unsignedBigInteger('subject_id');
                $t->string('subject_ref', 40)->nullable();
                $t->string('event', 32)->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->text('reason')->nullable();
                $t->json('old_value')->nullable();
                $t->json('new_value')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->softDeletes();
                $t->index(['subject_type', 'subject_id'], 'visitor_events_subject_idx');
            });
        }

        $accounts = Schema::hasTable('accounts') ? DB::table('accounts')->pluck('id')->all() : [];
        foreach ($accounts ?: [1] as $accountId) {
            $this->seedOptions((int) $accountId);
        }

        $names = array_keys(PermissionRegistry::modules()['visitor_logs']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (array_keys(SystemFeatureRegistry::features()) as $key) {
                if (! str_starts_with($key, 'visitor_')) {
                    continue;
                }
                $f = SystemFeatureRegistry::features()[$key];
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

    /** The lists a Super Admin can change later on the Visitor Logs settings page. Only filled when an account has none yet. */
    private function seedOptions(int $accountId): void
    {
        $lists = [
            'purpose' => [['Business meeting'], ['Official correspondence'], ['Project meeting'], ['Contractor / subcontractor meeting'], ['Supplier delivery'], ['Interview'], ['Client meeting'], ['Maintenance / service visit'], ['Personal appointment'], ['Other', 'other']],
            'item_type' => [['Letter', 'document'], ['Document', 'document'], ['Official Correspondence', 'document'], ['Package', 'package'], ['Parcel', 'package'], ['Courier Delivery', 'package']],
            'item_status' => [['Received at Reception', 'initial'], ['Recipient Notified', null], ['Ready for Collection', 'ready'], ['Delivered to Recipient', 'handed'], ['Collected', 'handed'], ['Returned to Sender', 'closed']],
            'department' => [['Management'], ['Accounts'], ['Administration'], ['Procurement'], ['Projects'], ['Stores'], ['Human Resources']],
            'visitor_category' => [['Client'], ['Supplier'], ['Contractor'], ['Subcontractor'], ['Official / authority'], ['Interviewee'], ['Courier'], ['Other']],
        ];
        foreach ($lists as $kind => $rows) {
            if (DB::table('visitor_options')->where('account_id', $accountId)->where('kind', $kind)->exists()) {
                continue;
            }
            foreach ($rows as $i => $r) {
                DB::table('visitor_options')->insert(['account_id' => $accountId, 'kind' => $kind, 'name' => $r[0], 'flag' => $r[1] ?? null, 'is_active' => true, 'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_log_events');
        Schema::dropIfExists('visitor_incoming_items');
        Schema::dropIfExists('visitor_visits');
        Schema::dropIfExists('visitors');
        Schema::dropIfExists('visitor_options');
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['visitor_logs']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'like', 'visitor\\_%')->delete();
            FeatureManager::flushCache();
        }
    }
};
