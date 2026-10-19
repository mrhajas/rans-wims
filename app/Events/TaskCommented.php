<?php

namespace App\Events;

use App\Models\Task;
use App\Models\TaskComment;

class TaskCommented
{
    public function __construct(public Task $task, public TaskComment $comment, public int $actorUserId)
    {
    }
}
