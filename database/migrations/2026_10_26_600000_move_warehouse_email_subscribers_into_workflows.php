<?php

use App\Models\MaterialRequestNotificationRule;
use App\Models\WorkflowNotificationRule;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The separate "Warehouse Email Notifications" list is retired: its emails are now events of the module workflow pages
 * (Check-in / Check-out / Transfer / Adjustment / Material Reallocation / Site Report "Email Report"; Material Request email goes to the
 * workflow's own audience). Every active subscriber who is a WIMS user (matched by login email or staff personal email) is added to those
 * events and gets their email switched on in Recipients, so the same people receive the same emails. A subscriber who is not a user cannot
 * be kept in the contact book; they are written to the log so they can be added as a user.
 */
return new class extends Migration
{
    private const EVENTS = [
        ['checkins', 'report'], ['checkouts', 'report'], ['transfers', 'report'], ['adjustments', 'report'],
        ['reallocations', 'report_cleared'], ['reallocations', 'report_final_approved'], ['site_reports', 'report'],
    ];

    private const MR_EVENTS = ['created', 'updated', 'deleted', 'status_changed', 'delivery_updated'];

    public function up(): void
    {
        if (! Schema::hasTable('warehouse_email_subscribers')) {
            return;
        }
        $subs = DB::table('warehouse_email_subscribers')->where('active', 1)->get();
        foreach ($subs as $s) {
            $email = strtolower(trim((string) $s->email));
            $userId = $s->user_id ?: DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->value('id')
                ?: DB::table('staffs')->whereRaw('LOWER(personal_email) = ?', [$email])->whereNotNull('user_id')->whereNull('deleted_at')->value('user_id');
            if (! $userId) {
                Log::warning('Warehouse email subscriber is not a WIMS user and was not moved: ' . $s->email);
                continue;
            }
            $account = (int) ($s->account_id ?: DB::table('users')->where('id', $userId)->value('account_id') ?: 1);

            // the contact book: this address, switched on
            DB::table('wims_connect_recipient_channels')->updateOrInsert(
                ['user_id' => $userId, 'channel' => 'email'],
                ['address' => $s->email, 'is_enabled' => true, 'auto_filled' => false, 'updated_at' => now(), 'created_at' => now()]
            );

            foreach (self::EVENTS as [$module, $event]) {
                $row = WorkflowNotificationRule::where('account_id', $account)->where('module', $module)->where('event_key', $event)->first();
                if (! $row) {
                    $e = WorkflowSettings::effective($module, $account, $event, null);
                    $row = WorkflowNotificationRule::create(['account_id' => $account, 'module' => $module, 'event_key' => $event, 'role_names' => $e['role_names'], 'user_ids' => $e['user_ids']] + array_intersect_key($e, array_flip(WorkflowSettings::FLAGS)));
                }
                $row->update(['user_ids' => array_values(array_unique(array_merge($row->user_ids ?? [], [(int) $userId]))), 'email_enabled' => true, 'is_enabled' => true]);
            }

            foreach (self::MR_EVENTS as $event) {
                $default = MaterialRequestNotificationRule::defaults()[$event] ?? null;
                if (! $default) {
                    continue;
                }
                $row = MaterialRequestNotificationRule::where('account_id', $account)->where('event_key', $event)->first()
                    ?? MaterialRequestNotificationRule::create(['account_id' => $account, 'event_key' => $event] + $default);
                $row->update(['user_ids' => array_values(array_unique(array_merge($row->user_ids ?? [], [(int) $userId]))), 'email_enabled' => true]);
            }
        }
    }

    public function down(): void
    {
        // the subscribers table is kept, so nothing to restore
    }
};
