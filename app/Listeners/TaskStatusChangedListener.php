<?php

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Models\User;
use App\Services\TaskWorkflowNotifier;

/** Who is told, on which channels and with which words is set on the Task Manager workflow page (event "Task Status Changed"). */
class TaskStatusChangedListener
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;
        $actor = User::find($event->actorUserId);
        $actorName = $actor?->name ?? __('Someone');

        TaskWorkflowNotifier::fire('status_changed', $task, $actor, 'Task Status Changed', $task->title . ' moved from ' . $event->previousStatus . ' to ' . $task->status . ' by ' . $actorName . '.', [
            'smsline' => "WIMS: \"{$task->title}\" moved to " . str_replace('_', ' ', $task->status) . " by {$actorName}.",
        ]);
    }
}
