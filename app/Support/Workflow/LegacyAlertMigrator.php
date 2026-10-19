<?php

namespace App\Support\Workflow;

use App\Models\EmailFunctionSubscription;
use App\Models\MaterialRequestNotificationRule;
use App\Models\NotificationRule;
use App\Models\SalaryNotificationRule;
use App\Models\WimsConnectRule;
use App\Models\WorkflowNotificationRule;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Support\Facades\DB;

/**
 * Copies what was set up in the three older places into each module's workflow page, so nothing has to be typed again:
 *  - the Notification Center (notification_rules): channels, roles, people, creator;
 *  - the WIMS Connect Communication Rules (wims_connect_rules): the SMS / WhatsApp / Meta channels and who they reach;
 *  - the Email Alerts matrix (email_function_subscriptions): the people who followed an email;
 *  - the older Salary notification rules.
 * Audiences are now one list per event (the old pages could differ per channel); the summary says where that merged anything.
 * Safe to run again: it only fills what the workflow page does not already have a saved row for, unless $force.
 */
class LegacyAlertMigrator
{
    /** @return array{notes: list<string>, events: int} */
    public static function run(bool $force = false): array
    {
        $notes = [];
        $touched = [];

        DB::transaction(function () use (&$notes, &$touched, $force) {
            // 1. Notification Center
            foreach (NotificationRule::query()->get() as $r) {
                foreach (WorkflowModules::findAll('nc:' . $r->event_key) as $t) {
                    $row = self::row($t, (int) $r->account_id, $force);
                    if (! $row) {
                        continue;
                    }
                    $row->fill([
                        'is_enabled' => (bool) $r->enabled, 'bell_enabled' => (bool) $r->bell, 'sms_enabled' => (bool) $r->sms, 'email_enabled' => (bool) $r->email,
                        'role_names' => array_values($r->role_names ?? []), 'user_ids' => array_values($r->user_ids ?? []), 'notify_owner' => (bool) $r->notify_owner,
                    ])->save();
                    if ($r->delivery === 'scheduled') {
                        $notes[] = "Notification Center '{$r->event_key}' was sent once a day at a set time; it now sends straight away.";
                    }
                    $touched[$t['module'] . '.' . $t['event']] = true;
                }
            }

            // 2. WIMS Connect Communication Rules
            $byEvent = WimsConnectRule::query()->where('is_enabled', true)->get()->groupBy('event_key');
            foreach ($byEvent as $eventKey => $rules) {
                foreach (WorkflowModules::findAll('wc:' . $eventKey) as $t) {
                    $account = (int) (DB::table('users')->where('id', $rules->first()->created_by)->value('account_id') ?: 1);
                    $row = self::row($t, $account, $force, true);
                    if (! $row) {
                        continue;
                    }
                    $roles = (array) ($row->role_names ?? []);
                    $users = (array) ($row->user_ids ?? []);
                    $everyone = (bool) $row->notify_everyone;
                    $project = (bool) $row->limit_to_project;
                    $channels = [];
                    foreach ($rules as $rule) {
                        $channels[$rule->channel] = true;
                        if ($rule->scope_type === 'global') {
                            $everyone = true;
                        } elseif ($rule->scope_type === 'user' && $rule->user_id) {
                            $users[] = (int) $rule->user_id;
                        } else {
                            if ($rule->role_name) {
                                $roles[] = $rule->role_name;
                            } else {
                                $everyone = true; // a whole project
                            }
                            if ($rule->warehouse_id) {
                                $project = true;
                            }
                        }
                    }
                    $row->fill([
                        'role_names' => array_values(array_unique($roles)), 'user_ids' => array_values(array_unique($users)), 'notify_everyone' => $everyone, 'limit_to_project' => $project,
                        'sms_enabled' => $row->sms_enabled || isset($channels['sms']), 'whatsapp_enabled' => $row->whatsapp_enabled || isset($channels['whatsapp']), 'meta_enabled' => $row->meta_enabled || isset($channels['meta']),
                    ])->save();
                    if (count($channels) > 1) {
                        $notes[] = "Communication Rules for '{$eventKey}' used different people per channel; they are now one list for all channels.";
                    }
                    $touched[$t['module'] . '.' . $t['event']] = true;
                }
            }

            // 3. Email Alerts matrix
            foreach (EmailFunctionSubscription::query()->get()->groupBy('function_key') as $function => $subs) {
                $ids = $subs->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
                $account = (int) ($subs->first()->account_id ?: 1);
                if ($function === 'material-request-lifecycle') {
                    foreach (['created', 'updated', 'status_changed', 'cleared'] as $ev) {
                        $mr = MaterialRequestNotificationRule::where('account_id', $account)->where('event_key', $ev)->first();
                        if ($mr && $mr->email_enabled) {
                            $mr->update(['user_ids' => array_values(array_unique(array_merge($mr->user_ids ?? [], $ids)))]);
                        }
                    }
                    continue;
                }
                foreach (WorkflowModules::findAll('em:' . $function) as $t) {
                    $row = self::row($t, $account, $force, true);
                    if (! $row) {
                        continue;
                    }
                    $row->fill(['email_enabled' => true, 'user_ids' => array_values(array_unique(array_merge((array) ($row->user_ids ?? []), $ids)))])->save();
                    $touched[$t['module'] . '.' . $t['event']] = true;
                }
            }

            // 4. Older Salary notification rules
            foreach (SalaryNotificationRule::query()->get() as $r) {
                if (! isset(WorkflowModules::get('salary')['events'][$r->event_key])) {
                    continue;
                }
                $row = self::row(['module' => 'salary', 'event' => $r->event_key], (int) $r->account_id, $force);
                if (! $row) {
                    continue;
                }
                $row->fill([
                    'role_names' => array_values($r->role_names ?? []), 'user_ids' => array_values($r->user_ids ?? []), 'notify_owner' => (bool) $r->notify_staff,
                    'email_enabled' => (bool) $r->email_enabled, 'sms_enabled' => (bool) $r->sms_enabled,
                ])->save();
                $touched['salary.' . $r->event_key] = true;
            }
        });

        return ['notes' => array_values(array_unique($notes)), 'events' => count($touched)];
    }

    /** The saved row for one event, created from the page's starting values the first time (null = leave an already saved row alone). */
    private static function row(array $t, int $accountId, bool $force, bool $merge = false): ?WorkflowNotificationRule
    {
        $row = WorkflowNotificationRule::where('account_id', $accountId)->where('module', $t['module'])->where('event_key', $t['event'])->first();
        if ($row && ! $force && ! $merge) {
            return null;
        }
        if ($row) {
            return $row;
        }
        $e = WorkflowSettings::effective($t['module'], $accountId, $t['event'], null);

        return WorkflowNotificationRule::create([
            'account_id' => $accountId, 'module' => $t['module'], 'event_key' => $t['event'],
            'role_names' => $e['role_names'], 'user_ids' => $e['user_ids'],
        ] + array_intersect_key($e, array_flip(WorkflowSettings::FLAGS)));
    }
}
