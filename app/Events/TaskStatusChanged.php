<?php

namespace App\Events;

use App\Models\Task;

class TaskStatusChanged
{
    public function __construct(public Task $task, public string $previousStatus, public int $actorUserId)
    {
    }
}
