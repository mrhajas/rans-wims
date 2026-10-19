<?php

namespace App\Services;

use App\Models\CaseTicket;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Hands every Case Tracking notice to the Case Tracking workflow page's settings (Who Gets Notified / Messages). It replaces the older
 * CaseSmsNotifier and the per-listener sending. Events: submitted, in_review, in_progress, returned, resolved, reopened, closed,
 * assigned, commented, deleted.
 *
 * "The owner" is the case creator plus the person it is assigned to (for "assigned": only the person it was just assigned to).
 * The person who acted is left out of the roles / named people, but a creator or assignee who acted still gets their own receipt
 * (except for comments and deletions, where nobody is told about their own action).
 */
class CaseWorkflowNotifier
{
    public const MODULE = 'case_tracking';

    /** A Case Tracking settings change (category or priority): not about one case. */
    public static function settings(string $message, ?User $actor): void
    {
        try {
            WorkflowMessenger::dispatch(self::MODULE, 'settings_changed', null, $actor, [
                'account_id' => (int) ($actor?->account_id ?? 1), 'title' => __('Case Settings Changed'), 'message' => $message, 'url' => route('case-tracking.index'),
                'skip_actor' => true, 'dedupe' => md5($message) . '|' . intdiv(time(), 20),
                'values' => ['casenumber' => '', 'subject' => '', 'status' => '', 'creator' => '', 'assignee' => '', 'note' => '', 'comment' => ''],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CaseWorkflowNotifier settings failed', ['error' => $e->getMessage()]);
        }
    }

    public static function fire(string $event, CaseTicket $case, ?int $actorUserId, string $title, string $message, array $values = []): bool
    {
        $case->loadMissing(['createdBy', 'assignedTo', 'warehouse']);
        $actor = $actorUserId ? User::find($actorUserId) : null;
        $owners = $event === 'assigned' ? [$case->assignedTo] : [$case->createdBy, $case->assignedTo];
        if ($event === 'created') {
            $owners = []; // the creator did it: only the roles / people chosen on the page are told
        }

        return WorkflowMessenger::dispatch(self::MODULE, $event, $case, $actor, [
            'account_id' => (int) ($case->createdBy?->account_id ?? optional($case->warehouse)->account_id ?? 1),
            'title' => $title, 'message' => $message, 'url' => route('case-tracking.show', $case->id),
            'owner_users' => array_values(array_filter($owners)),
            'skip_actor' => in_array($event, ['commented', 'deleted', 'created', 'updated'], true),
            'dedupe' => now()->format('YmdHisu'), // every occurrence is its own notice (a case can be commented on many times)
            'values' => $values + [
                'casenumber' => (string) $case->case_number, 'subject' => (string) $case->subject, 'status' => (string) $case->status,
                'creator' => (string) ($case->createdBy?->name ?? ''), 'assignee' => (string) ($case->assignedTo?->name ?? ''),
            ],
        ]);
    }
}
