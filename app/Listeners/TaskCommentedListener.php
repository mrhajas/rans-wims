<?php

namespace App\Listeners;

use App\Events\TaskCommented;
use App\Models\User;
use App\Services\TaskWorkflowNotifier;
use Illuminate\Support\Str;

/** Who is told, on which channels and with which words is set on the Task Manager workflow page (event "Task Comment Added"). */
class TaskCommentedListener
{
    public function handle(TaskCommented $event): void
    {
        $task = $event->task;
        $comment = $event->comment;
        $actor = User::find($event->actorUserId);

        TaskWorkflowNotifier::fire('commented', $task, $actor, 'New Comment', $comment->user?->name . ' commented on ' . $task->title . ': "' . Str::limit($comment->body, 120) . '"', [
            'values' => ['comment' => Str::limit($comment->body, 120), 'commentshort' => Str::limit($comment->body, 80)],
            'dedupe' => 'comment' . $comment->id,
        ]);
    }
}
