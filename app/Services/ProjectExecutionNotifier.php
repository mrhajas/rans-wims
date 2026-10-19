<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageDailyUpdate;
use App\Models\WorkPackageIssue;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Project Execution alerts. Who is told, on which channels and with which words is set on the Project Execution workflow page
 * (project-execution/workflow); this class says which event happened.
 */
class ProjectExecutionNotifier
{
    public static function issue(string $event, WorkPackageIssue $issue, string $title, string $message, ?int $ownerId, ?string $remarks = null): void
    {
        $wp = $issue->workPackage()->first();
        $assignee = $issue->assigned_user_id ? User::whereKey($issue->assigned_user_id)->first(['id', 'name']) : null;
        self::send($event, $issue, $wp, $title, $message, $ownerId, $remarks, [
            'reference' => (string) $issue->reference, 'issue' => (string) $issue->title, 'priority' => (string) $issue->priority, 'assignee' => (string) optional($assignee)->name,
        ]);
    }

    public static function dailyUpdate(string $event, WorkPackageDailyUpdate $update, string $title, string $message, ?string $remarks = null): void
    {
        $wp = $update->workPackage()->first();
        self::send($event, $update, $wp, $title, $message, (int) $update->submitted_by, $remarks, [
            'progress' => rtrim(rtrim(number_format((float) $update->progress_percent, 2, '.', ''), '0'), '.') . '%',
        ]);
    }

    private static function send(string $event, $record, ?WorkPackage $wp, string $title, string $message, ?int $ownerId, ?string $remarks, array $values): void
    {
        $actor = auth()->user();
        $warehouse = $wp?->warehouse_id ? \App\Models\Warehouse::withoutGlobalScopes()->whereKey($wp->warehouse_id)->value('name') : null;
        WorkflowMessenger::dispatch('project_execution', $event, $record, $actor, [
            'account_id' => $actor?->account_id ?? ($ownerId ? (User::whereKey($ownerId)->value('account_id') ?: 1) : 1),
            'title' => $title, 'message' => $message,
            'url' => $wp ? route('work-packages.show', $wp->id) : null,
            'owner_user_id' => (int) $ownerId,
            'warehouse_ids' => $wp?->warehouse_id ? [$wp->warehouse_id] : null,
            'remarks' => (string) $remarks,
            'dedupe' => $record->getKey() . '|' . $event . '|' . intdiv(time(), 20),
            'values' => $values + [
                'workpackage' => $wp ? trim($wp->reference . ' ' . $wp->title) : '', 'project' => (string) $warehouse,
            ],
        ]);
    }
}
