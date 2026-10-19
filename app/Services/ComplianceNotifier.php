<?php

namespace App\Services;

use App\Models\ComplianceRecord;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Support\Collection;

/**
 * Compliance alerts. Who is told, on which channels and with which words is set on the Compliance workflow page
 * (compliance-records/workflow); this class says which event happened and who counts as the owner of the record
 * (the responsible person plus the extra recipients chosen on it).
 */
class ComplianceNotifier
{
    public static function accountId(ComplianceRecord $record): int
    {
        return (int) (User::whereKey($record->responsible_user_id ?: $record->escalation_recipient_user_id ?: auth()->id())->value('account_id') ?: (auth()->user()?->account_id ?? 1));
    }

    /** The responsible person and the extra recipients chosen on the record. */
    public static function owners(ComplianceRecord $record): Collection
    {
        $ids = array_merge([$record->responsible_user_id], (array) $record->additional_recipient_user_ids);

        return User::whereIn('id', array_filter(array_map('intval', $ids)))->get(['id', 'name']);
    }

    private static function context(ComplianceRecord $record, string $title, string $message, array $extra = []): array
    {
        $responsible = $record->relationLoaded('responsibleUser') ? $record->responsibleUser : $record->responsibleUser()->first();

        return $extra + [
            'account_id' => self::accountId($record),
            'title' => $title, 'message' => $message, 'url' => route('compliance-records.show', $record->id),
            'owner_user_id' => (int) $record->responsible_user_id,
            'owner_users' => self::owners($record),
            'warehouse_ids' => $record->warehouse_id ? [$record->warehouse_id] : null,
            'values' => [
                'record' => (string) $record->title, 'number' => (string) $record->compliance_number, 'type' => (string) $record->compliance_type,
                'days' => (string) ($record->days_remaining ?? ''), 'expirydate' => $record->expiry_date ? $record->expiry_date->format('d M Y') : '',
                'responsible' => (string) optional($responsible)->name, 'status' => (string) $record->status,
            ],
        ];
    }

    /** A change made by a person (created, cancelled, renewal steps). */
    public static function fire(string $event, ComplianceRecord $record, string $title, string $message, ?string $remarks = null): void
    {
        $ctx = self::context($record, $title, $message, ['remarks' => (string) $remarks, 'dedupe' => $record->id . '|' . $event . '|' . intdiv(time(), 20)]);
        WorkflowMessenger::dispatch('compliance', $event, $record, auth()->user(), $ctx);
    }

    /** A change made to a record that carries extra tag values (a document name ...). */
    public static function fireWith(string $event, ComplianceRecord $record, string $title, string $message, array $values): void
    {
        $ctx = self::context($record, $title, $message, ['dedupe' => $record->id . '|' . $event . '|' . intdiv(time(), 20)]);
        $ctx['values'] = $values + $ctx['values'];
        WorkflowMessenger::dispatch('compliance', $event, $record, auth()->user(), $ctx);
    }

    /** A change on the Compliance Categories screen (the category is the record). */
    public static function fireCategory(string $event, \App\Models\ComplianceCategory $category, string $title, string $message): void
    {
        $actor = auth()->user();
        WorkflowMessenger::dispatch('compliance', $event, $category, $actor, [
            'account_id' => (int) ($actor?->account_id ?? 1), 'title' => $title, 'message' => $message,
            'url' => route('compliance-records.categories.index'),
            'dedupe' => 'cat|' . $category->id . '|' . $event . '|' . intdiv(time(), 20),
            'values' => ['category' => (string) $category->name],
        ]);
    }

    /**
     * The daily reminder / escalation. Returns the people who were told (so the command can write its reminder log), or an empty
     * collection when the event is switched off or nobody is in the audience.
     *
     * @param  ?User  $onlyOwner  escalation: the one escalation recipient counts as the owner, not the responsible person
     */
    public static function remind(string $event, ComplianceRecord $record, string $title, string $message, string $scope, ?User $onlyOwner = null): Collection
    {
        $accountId = self::accountId($record);
        $rule = WorkflowSettings::effective('compliance', $accountId, $event);
        if (! $rule['is_enabled']) {
            return collect();
        }
        $ctx = self::context($record, $title, $message, ['dedupe' => $record->id . '|' . $event . '|' . $scope]);
        if ($onlyOwner) {
            $ctx['owner_user_id'] = (int) $onlyOwner->id;
            $ctx['owner_users'] = collect([$onlyOwner]);
        }
        $def = WorkflowModules::get('compliance')['events'][$event];
        $audience = WorkflowMessenger::audience('compliance', $rule, $def, $accountId, (int) $ctx['owner_user_id'], null, $ctx, $record);
        if ($audience->isEmpty()) {
            return collect();
        }
        WorkflowMessenger::dispatch('compliance', $event, $record, null, $ctx);

        return $audience;
    }
}
