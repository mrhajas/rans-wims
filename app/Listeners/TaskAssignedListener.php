<?php

namespace App\Listeners;

use App\Events\TaskAssigned;
use App\Models\User;
use App\Services\TaskWorkflowNotifier;

/**
 * Who is told, on which channels and with which words is set on the Task Manager workflow page (event "Task Assigned"). One notice for the
 * whole save, however many people were assigned.
 */
class TaskAssignedListener
{
    public function handle(TaskAssigned $event): void
    {
        $task = $event->task;
        $assignees = $event->taskAssignees->map(fn ($ta) => $ta->assignee)->filter()->values();

        if ($assignees->isEmpty()) {
            return;
        }

        $actor = User::find($event->actorUserId);
        $actorName = $actor?->name ?? __('Someone');
        $names = $assignees->pluck('name')->filter()->implode(', ');
        $message = $task->title . ' has been assigned to ' . $names . ' by ' . $actorName . '.';

        TaskWorkflowNotifier::fire('assigned', $task, $actor, 'Task Assigned', $message, [
            'new_users' => $assignees->filter(fn ($a) => $a instanceof User)->values()->all(),
            'values' => ['assignees' => $names],
            'smsline' => "WIMS: \"{$task->title}\" was assigned to {$names} by {$actorName}.",
        ]);
    }
}
