<?php

namespace App\Events;

use App\Models\Task;
use App\Models\TaskAssignee;
use Illuminate\Support\Collection;

// Dispatched ONCE per save with every newly-added assignee (see TaskManagerController::store /
// syncAssignees). It used to fire once per assignee, so a task with two assignees sent each
// recipient two near-identical notifications; now each recipient gets exactly one that names everybody.
class TaskAssigned
{
    /** @var Collection<int, TaskAssignee> */
    public Collection $taskAssignees;

    /** @param TaskAssignee|iterable<TaskAssignee> $taskAssignees */
    public function __construct(public Task $task, TaskAssignee|iterable $taskAssignees, public int $actorUserId)
    {
        $this->taskAssignees = $taskAssignees instanceof TaskAssignee ? collect([$taskAssignees]) : collect($taskAssignees);
    }
}
