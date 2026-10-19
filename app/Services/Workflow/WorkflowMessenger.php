<?php

namespace App\Services\Workflow;

use App\Models\LabourList;
use App\Models\ProjectCertificate;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\SalarySmsNotifier;
use App\Services\WimsConnect\CommunicationService;
use App\Support\MaterialRequestMessageTags as Tags;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the notices of the workflow modules (Labour Lists, Project Certificates, Salary) the way the Material Request workflow does:
 * the audience, the channels and the texts come from what a Super Admin saved on that module's workflow page. SMS goes to each person's
 * own number on WIMS Connect > Recipients. dispatch() returns true when a saved setting handled the event (the caller then skips its
 * own older sending) and false when nothing was saved for it (the caller carries on exactly as before).
 *
 * $ctx: account_id, owner_user_id, owner_users (more people counted as the owner), owner_staff (Salary employee), salary_record, remarks, amount, remaining, dedupe, url.
 */
class WorkflowMessenger
{
    /**
     * Sends one event of a module the way its workflow page says. Always returns true (the page's starting values reproduce the
     * module's earlier behaviour, so there is no separate "older" path any more).
     *
     * $ctx: account_id, title, message, url, details (facts for the email), items, values (extra #tags), owner_user_id, owner_staff,
     * salary_record, warehouse_ids (the record's project(s), for "stay inside the project"), candidates (limit to these people),
     * remarks, amount, remaining, dedupe.
     */
    public static function dispatch(string $module, string $eventKey, ?Model $record = null, ?User $actor = null, array $ctx = []): bool
    {
        try {
            $accountId = (int) ($ctx['account_id'] ?? $actor?->account_id ?? 1);
            $rule = WorkflowSettings::effective($module, $accountId, $eventKey);
            if (! $rule['is_enabled']) {
                return true;
            }
            $cfg = WorkflowModules::get($module);
            $def = $cfg['events'][$eventKey];
            $values = self::values($module, $record, $actor, $ctx);
            $scope = (string) ($ctx['dedupe'] ?? (($record?->getKey() ?? '0') . '|' . intdiv(time(), 20)));
            $url = $ctx['url'] ?? ($record ? self::url($module, $record) : null);
            $ownerId = (int) ($ctx['owner_user_id'] ?? 0);

            $recipients = self::audience($module, $rule, $def, $accountId, $ownerId, $actor, $ctx, $record);
            $comm = app(CommunicationService::class);
            $source = $record ?? \App\Models\Account::find($accountId);

            foreach ($recipients as $user) {
                $v = ['recipient' => $user->name] + $values;
                self::bellAndEmail($module, $eventKey, $user, $rule, $def, $v, $url, $ctx, $scope);
                if (! empty($ctx['rich_mail']) && $rule['email_enabled'] && $source) {
                    self::richMail($module, $eventKey, $user, $rule, $def, $v, $ctx, $source, $scope);
                }
                foreach (['sms' => 'sms_template', 'whatsapp' => 'whatsapp_template', 'meta' => 'meta_template'] as $channel => $tpl) {
                    if (! $rule[$channel . '_enabled']) {
                        continue;
                    }
                    $text = Tags::render($rule[$tpl], $v, true);
                    if (trim($text) !== '' && $source) {
                        $comm->notifyDirect($channel, $module . '-' . str_replace('_', '-', $eventKey), $source, collect([$user]), $text, $scope);
                    }
                }
            }

            // Salary: the employee (a Staff record, not always a user)
            if ($rule['notify_owner'] && ! empty($ctx['owner_staff'])) {
                $staff = $ctx['owner_staff'];
                $v = ['recipient' => $staff->name] + $values;
                $staff->notify(new AppNotification([
                    'module' => 'salary', 'type' => 'salary_' . $eventKey, 'title' => self::subject($rule, $v, $def['label']),
                    'message' => trim(Tags::render($rule['email_body'], $v)), 'send_email' => (bool) $rule['email_enabled'], 'created_at' => now()->toDateTimeString(),
                ]));
                if ($rule['sms_enabled'] && ($ctx['salary_record'] ?? null) instanceof SalaryRecord) {
                    SalarySmsNotifier::send($ctx['salary_record'], $eventKey, Tags::render($rule['sms_template'], $v, true));
                }
            }
        } catch (\Throwable $e) {
            Log::error('WorkflowMessenger failed', ['module' => $module, 'event' => $eventKey, 'error' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * While a settings page works out who each event reaches (dozens of events in one request), the same people are asked for again and again.
     * Set to an empty array for that time: audience() then keeps the people of a role / permission / "everyone" and reuses them. null = off (every normal send).
     */
    public static ?array $reachMemo = null;

    private static function remember(string $key, \Closure $load): Collection
    {
        if (self::$reachMemo === null) {
            return $load();
        }

        return self::$reachMemo[$key] ??= $load();
    }

    /** Roles + named users, everyone, the next step's owners (or the people allowed to do it), the creator, kept inside the project when asked. */
    public static function audience(string $module, array $rule, array $def, int $accountId, int $ownerId, ?User $actor, array $ctx, ?Model $record): Collection
    {
        $active = fn () => User::where('account_id', $accountId)->where('is_active', true);
        $users = collect();
        if (! empty($rule['notify_everyone'])) {
            $users = $users->merge(self::remember("all|$accountId", fn () => $active()->get()));
        }
        if (! empty($rule['role_names'])) {
            $users = $users->merge(self::remember("roles|$accountId|" . implode(',', $rule['role_names']), fn () => $active()->whereHas('roles', fn ($q) => $q->whereIn('name', $rule['role_names']))->get()));
        }
        if (! empty($rule['user_ids'])) {
            $users = $users->merge(User::whereIn('id', $rule['user_ids'])->where('is_active', true)->get());
        }
        if (! empty($rule['notify_step_owner'])) {
            $stepsModel = WorkflowModules::get($module)['steps_model'] ?? null;
            if (! empty($def['step']) && $stepsModel) {
                $users = $users->merge($stepsModel::assignedUsers($accountId, $def['step']));
            } elseif (! empty($def['permission'])) {
                $users = $users->merge(self::remember('perm|' . $def['permission'], fn () => User::permission($def['permission'])->where('is_active', true)->get()));
            }
        }
        $users = $users->unique('id');

        if (! empty($rule['limit_to_project'])) {
            $wids = $ctx['warehouse_ids'] ?? ($record && isset($record->warehouse_id) && $record->warehouse_id ? [$record->warehouse_id] : null);
            if ($wids) {
                $assigned = DB::table('user_warehouse')->whereIn('warehouse_id', (array) $wids)->pluck('user_id')->map(fn ($i) => (int) $i)->all();
                // Super Admin and Admin are never held back by the project limit
                $users = $users->filter(fn ($u) => in_array((int) $u->id, $assigned, true) || $u->hasRole(['Super Admin', 'Admin']));
            }
        }
        if (! empty($ctx['candidates'])) {
            $ids = collect($ctx['candidates'])->pluck('id')->map(fn ($i) => (int) $i)->all();
            $users = $users->filter(fn ($u) => in_array((int) $u->id, $ids, true));
        }
        if ($actor && empty($ctx['include_actor'])) {
            $users = $users->reject(fn ($u) => (int) $u->id === (int) $actor->id && (int) $u->id !== $ownerId);
        }
        if (! empty($rule['notify_owner']) && $ownerId && ($owner = User::where('id', $ownerId)->where('is_active', true)->first())) {
            $users = $users->push($owner)->unique('id');
        }
        // more people the module counts as "the owner" of this record (for example the creator AND the assignee of a task)
        if (! empty($rule['notify_owner']) && ! empty($ctx['owner_users'])) {
            $extra = User::whereIn('id', collect($ctx['owner_users'])->pluck('id')->filter()->all())->where('is_active', true)->get();
            $users = $users->merge($extra)->unique('id');
        }
        if ($actor && ! empty($ctx['skip_actor'])) {
            $users = $users->reject(fn ($u) => (int) $u->id === (int) $actor->id); // nobody is told about their own action
        }

        return $users->values();
    }

    private static function bellAndEmail(string $module, string $eventKey, User $user, array $rule, array $def, array $values, ?string $url, array $ctx, string $scope): void
    {
        $bell = (bool) $rule['bell_enabled'];
        // an event that carries a full email report (items, history, PDF) sends that instead of the plain email
        $mail = (bool) $rule['email_enabled'] && empty($ctx['rich_mail']);
        if (! $bell && ! $mail) {
            return;
        }
        $key = 'alerts:' . md5($module . '|' . $eventKey . '|' . $user->id . '|' . $scope);
        if (! Cache::add($key, 1, 120)) {
            return; // the same notice for the same person was just sent
        }
        $notifModule = match ($module) { 'certificates' => 'project_certificates', 'reallocations' => 'material_reallocations', 'compliance' => 'compliance_records', 'project_execution' => 'work_package_issues', default => $module };
        $user->notify(new AppNotification(array_filter([
            'module' => $notifModule,
            'type' => str_replace('-', '_', $module . '_' . $eventKey),
            'title' => self::subject($rule, $values, $def['label']),
            'message' => trim(Tags::render($rule['email_body'], $values)),
            'status' => $values['status'] ?? null,
            'reference' => $values['reference'] ?? ($values['batch'] ?? null),
            'url' => $url,
            'details' => ($mail && $rule['email_show_details']) ? ($ctx['details'] ?? null) : null,
            'items' => ($mail && $rule['email_show_details']) ? ($ctx['items'] ?? null) : null,
            'send_email' => $mail ? true : null,
            'email_only' => (! $bell && $mail) ? true : null,
            'created_at' => now()->toDateTimeString(),
        ], fn ($v) => $v !== null)));
    }

    /**
     * The full email report of a warehouse activity (items table, audit trail, chain of custody, PDF), to the person's own address on
     * Recipients (only when their email is switched on there). The outcome of each send is kept in the WIMS Connect delivery log.
     * $ctx['rich_mail']: type, reference, warehouse, details, items, audit, chain, attachments [{data, filename, mime}].
     */
    private static function richMail(string $module, string $eventKey, User $user, array $rule, array $def, array $values, array $ctx, Model $source, string $scope): void
    {
        $address = $user->routeNotificationForMail(new AppNotification(['module' => $module]));
        if (blank($address)) {
            return; // no enabled email for this person on Recipients: skipped quietly
        }
        $r = $ctx['rich_mail'];
        $subject = self::subject($rule, $values, (string) ($ctx['title'] ?? $def['label']));
        $event = $module . '-' . str_replace('_', '-', $eventKey);
        $key = \App\Models\WimsConnectLog::buildIdempotencyKey($event, get_class($source), $source->getKey() ?? 0, $user->id, 'email', $scope);
        if (\App\Models\WimsConnectLog::where('idempotency_key', $key)->exists()) {
            return;
        }
        try {
            $mail = new \App\Mail\MainWarehouseActivityMail(
                (string) ($r['type'] ?? $module), (string) ($r['reference'] ?? ''), (string) ($r['warehouse'] ?? ''), (array) ($r['details'] ?? []), (array) ($r['items'] ?? []),
                $subject, (array) ($r['audit'] ?? []), (array) ($r['chain'] ?? []), (array) ($r['attachments'] ?? [])
            );
            $mail->eventKey = $event;
            // the Delivery Logs row of a sent email is written when Laravel sends it (OutboundLog::mailSent)
            \Illuminate\Support\Facades\Mail::to($address, $user->name)->send($mail);
        } catch (\Throwable $e) {
            Log::error('WorkflowMessenger: email report failed', ['module' => $module, 'event' => $eventKey, 'to' => $address, 'error' => $e->getMessage()]);
            \App\Models\WimsConnectLog::create([
                'idempotency_key' => $key, 'event_key' => $event, 'source_type' => get_class($source), 'source_id' => $source->getKey() ?? 0, 'recipient_user_id' => $user->id,
                'address' => $address, 'channel' => 'email', 'message' => $subject, 'subject' => $subject, 'status' => 'FAILED', 'error_message' => \Illuminate\Support\Str::limit(strip_tags($e->getMessage()), 300),
            ]);
        }
    }

    private static function subject(array $rule, array $values, string $fallback): string
    {
        $s = trim(preg_replace('/\s+/', ' ', Tags::render($rule['email_subject'], $values)));

        return $s !== '' ? $s : $fallback;
    }

    private static function url(string $module, Model $record): ?string
    {
        try {
            return match ($module) {
                'labour' => route('labour.show', $record->id),
                'certificates' => route('project-certificates.show', $record->id),
                'salary' => $record instanceof SalaryRecord ? route('salary.periods.show', $record->salary_period_id) : route('salary.periods.show', $record->id),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** tag (without #) => real value for this record. */
    public static function values(string $module, ?Model $record, ?User $actor, array $ctx = []): array
    {
        return array_merge(self::recordValues($module, $record, $actor, $ctx), ['title' => (string) ($ctx['title'] ?? ''), 'message' => (string) ($ctx['message'] ?? '')], array_change_key_case((array) ($ctx['values'] ?? []), CASE_LOWER));
    }

    private static function recordValues(string $module, ?Model $record, ?User $actor, array $ctx): array
    {
        $money = fn ($n, $cur = 'TZS') => ($cur ?: 'TZS') . ' ' . number_format((float) $n, 0);
        $base = ['actor' => $actor?->name ?? '', 'actiontime' => now()->format('d M Y, H:i'), 'remarks' => (string) ($ctx['remarks'] ?? '')];

        if ($record instanceof LabourList) {
            $record->loadMissing(['warehouse:id,name', 'createdBy:id,name']);

            return $base + [
                'reference' => $record->reference, 'project' => optional($record->warehouse)->name ?? '', 'status' => $record->status,
                'period' => ($record->period_start ? $record->period_start->format('d M Y') : '') . ($record->period_end ? ' to ' . $record->period_end->format('d M Y') : ''),
                'amount' => $money($ctx['amount'] ?? $record->total_amount), 'itemscount' => (string) $record->items()->count(),
                'creator' => optional($record->createdBy)->name ?? '', 'remarks' => (string) ($ctx['remarks'] ?? $record->review_remarks ?? ''),
            ];
        }
        if ($record instanceof ProjectCertificate) {
            $record->loadMissing(['warehouse', 'createdBy:id,name']); // the reference is built from the project code, so the whole project row is needed

            return $base + [
                'reference' => $record->reference, 'project' => optional($record->warehouse)->name ?? '', 'status' => $record->status,
                'type' => (string) $record->certificate_type, 'amount' => $money($ctx['amount'] ?? $record->net_payment_amount, $record->currency),
                'period' => (string) $record->period_covered, 'creator' => optional($record->createdBy)->name ?? '',
            ];
        }
        if ($record instanceof SalaryRecord) {
            // the whole staff row (not only id, name): SalarySmsNotifier reads staff.user_id from this same relation to find the employee's SMS number
            $record->loadMissing(['period:id,label,batch_reference,status', 'staff']);

            return $base + [
                'period' => optional($record->period)->label ?? '', 'batch' => optional($record->period)->batch_reference ?? '', 'status' => optional($record->period)->status ?? '',
                'employee' => optional($record->staff)->name ?? '', 'amount' => $money($ctx['amount'] ?? $record->current_payable), 'remaining' => $money($ctx['remaining'] ?? 0),
            ];
        }
        if ($record instanceof SalaryPeriod) {
            return $base + ['period' => $record->label, 'batch' => $record->batch_reference, 'status' => $record->status, 'employee' => '', 'amount' => '', 'remaining' => ''];
        }

        return $base;
    }
}
