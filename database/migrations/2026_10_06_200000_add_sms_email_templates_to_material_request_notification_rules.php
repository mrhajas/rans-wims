<?php

use App\Models\Account;
use App\Models\MaterialRequestNotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event SMS / email switches and editable templates for the Material Request workflow. Everything is additive.
 * Existing behaviour is preserved: the people who were getting a Material Request SMS through WIMS Connect rules
 * (a user or a whole role) are copied into that event's audience and its SMS switch is turned on; email stays on
 * for the events that already sent email.
 */
return new class extends Migration
{
    private const WIMS_EVENT_TO_RULE = [
        'material-request-submitted' => 'created',
        'material-request-approved' => 'approved',
        'material-request-rejected' => 'rejected',
        'material-request-cleared' => 'cleared',
    ];

    public function up(): void
    {
        Schema::table('material_request_notification_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('material_request_notification_rules', 'sms_enabled')) {
                $table->boolean('sms_enabled')->default(false)->after('notify_site_engineers');
            }
            if (! Schema::hasColumn('material_request_notification_rules', 'email_enabled')) {
                $table->boolean('email_enabled')->default(true)->after('sms_enabled');
            }
            if (! Schema::hasColumn('material_request_notification_rules', 'sms_template')) {
                $table->text('sms_template')->nullable()->after('email_enabled');
            }
            if (! Schema::hasColumn('material_request_notification_rules', 'email_subject')) {
                $table->string('email_subject', 255)->nullable()->after('sms_template');
            }
            if (! Schema::hasColumn('material_request_notification_rules', 'email_body')) {
                $table->text('email_body')->nullable()->after('email_subject');
            }
            if (! Schema::hasColumn('material_request_notification_rules', 'email_show_details')) {
                $table->boolean('email_show_details')->default(true)->after('email_body');
            }
            if (! Schema::hasColumn('material_request_notification_rules', 'email_show_items')) {
                $table->boolean('email_show_items')->default(true)->after('email_show_details');
            }
        });

        $defaults = MaterialRequestNotificationRule::messageDefaults();

        Account::all(['id'])->each(function ($account) use ($defaults) {
            // Make sure every event has a row, then give it its message defaults (templates stay NULL = "use the default").
            foreach (MaterialRequestNotificationRule::defaults() as $eventKey => $default) {
                $row = MaterialRequestNotificationRule::firstOrCreate(
                    ['account_id' => $account->id, 'event_key' => $eventKey],
                    collect($default)->only(['label', 'description', 'role_names', 'user_ids', 'notify_requester', 'notify_site_engineers'])->all()
                );
                $row->forceFill([
                    'sms_enabled' => $defaults[$eventKey]['sms_enabled'],
                    'email_enabled' => $defaults[$eventKey]['email_enabled'],
                ])->save();
            }

            // Carry over who was already getting a Material Request SMS (WIMS Connect rules scoped to a user or a role).
            if (! Schema::hasTable('wims_connect_rules')) {
                return;
            }
            foreach (self::WIMS_EVENT_TO_RULE as $wimsKey => $ruleKey) {
                $wims = DB::table('wims_connect_rules')->where('event_key', $wimsKey)->where('channel', 'sms')->where('is_enabled', 1)->get();
                if ($wims->isEmpty()) {
                    continue;
                }
                $row = MaterialRequestNotificationRule::where('account_id', $account->id)->where('event_key', $ruleKey)->first();
                if (! $row) {
                    continue;
                }
                $roles = collect($row->role_names ?? []);
                $users = collect($row->user_ids ?? []);
                foreach ($wims as $w) {
                    if ($w->scope_type === 'user' && $w->user_id) {
                        $users->push((int) $w->user_id);
                    } elseif ($w->scope_type === 'role' && $w->role_name) {
                        $roles->push($w->role_name);
                    }
                }
                $row->forceFill([
                    'role_names' => $roles->unique()->values()->all(),
                    'user_ids' => $users->unique()->values()->all(),
                    'sms_enabled' => true,
                ])->save();
            }
        });
    }

    public function down(): void
    {
        // Additive and harmless to keep.
    }
};
