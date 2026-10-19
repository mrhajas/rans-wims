<?php

namespace App\Services;

use App\Models\IssueReturn;
use App\Models\IssueTransaction;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Issue & Return alerts. Who is told, on which channels and with which words is set on the Issue & Return workflow page
 * (issue-returns/workflow); this class only says which event happened.
 */
class IssueReturnNotificationService
{
    public static function notifyNewIssue(IssueTransaction $issue): void
    {
        self::fire('issue_created', $issue, 'New Issue Created', $issue->issue_number . ' was created for ' . $issue->issued_to . '.');
    }

    public static function notifyUpdated(IssueTransaction $issue): void
    {
        self::fire('updated', $issue, 'Issue Edited', $issue->issue_number . ' was edited.');
    }

    public static function notifyDeleted(IssueTransaction $issue): void
    {
        self::fire('deleted', $issue, 'Issue Deleted', $issue->issue_number . ' was deleted.');
    }

    public static function notifyReturn(IssueTransaction $issue, IssueReturn $return): void
    {
        $event = 'item_returned';
        $title = 'Item Returned';
        $message = 'Return posted for ' . $issue->issue_number . '.';

        if ($issue->status === 'fully_returned') {
            $event = 'fully_returned';
            $title = 'Fully Returned';
            $message = $issue->issue_number . ' was fully returned.';
        } elseif ($issue->status === 'partially_returned') {
            $event = 'partial_return';
            $title = 'Partial Return';
            $message = $issue->issue_number . ' has a partial return.';
        }

        // A posted return always leaves the issue partly or fully returned, so "Item Returned" is the general notice that is used when the
        // more specific one for this return is switched off (with the starting settings both specific ones are on, so nothing changes).
        if ($event !== 'item_returned' && ! \App\Services\WorkflowGate::on('issue_returns', $event, (int) ($issue->account_id ?: 1))) {
            $event = 'item_returned';
            $title = 'Item Returned';
            $message = 'Return posted for ' . $issue->issue_number . '.';
        }

        // one notice per return (a second return on the same issue is a new notice)
        self::fire($event, $issue, $title, $message, 'return-' . $return->id);

        if ($return->items()->whereIn('condition', ['lost', 'damaged'])->exists()) {
            self::fire('lost_or_damaged', $issue, 'Lost or Damaged Items Returned', 'Check lost/damaged lines in ' . $issue->issue_number . '.', 'return-' . $return->id);
        }
    }

    private static function fire(string $event, IssueTransaction $issue, string $title, string $message, ?string $scope = null): void
    {
        $actor = auth()->user();
        $creator = $issue->user_id ? \App\Models\User::whereKey($issue->user_id)->value('name') : null;
        $warehouseName = $issue->warehouse_id ? \App\Models\Warehouse::withoutGlobalScopes()->whereKey($issue->warehouse_id)->value('name') : null;

        WorkflowMessenger::dispatch('issue_returns', $event, $issue, $actor, [
            'account_id' => $issue->account_id ?: ($actor?->account_id ?? 1),
            'title' => $title, 'message' => $message,
            'url' => route('issue-materials.show', $issue->id),
            'owner_user_id' => (int) $issue->user_id,
            'warehouse_ids' => $issue->warehouse_id ? [$issue->warehouse_id] : null,
            'dedupe' => $issue->id . '|' . $event . '|' . ($scope ?? intdiv(time(), 20)),
            'values' => [
                'reference' => $issue->issue_number, 'issuedto' => (string) $issue->issued_to, 'project' => (string) $issue->project,
                'warehouse' => (string) $warehouseName, 'department' => (string) $issue->department, 'status' => (string) $issue->status,
                'expectedreturn' => $issue->expected_return_date ? $issue->expected_return_date->format('d M Y') : '',
                'creator' => (string) $creator,
            ],
        ]);
    }
}
