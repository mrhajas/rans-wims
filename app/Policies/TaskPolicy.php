<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin'])
            || $user->can('read-task-manager')
            || $user->can('read-all-task-manager');
    }

    public function view(User $user, Task $task): bool
    {
        // A private task (a Super Admin's personal "My Tasks" item) is
        // exclusive to its creator and current assignees — this check runs
        // before, and overrides, every other bypass below, including
        // Admin/read-all-task-manager. (Gate::before carries the matching
        // carve-out for Super Admin, whose blanket bypass would otherwise
        // never even reach this method.)
        if ($task->is_private) {
            return $this->isOwnOrAssigned($user, $task);
        }

        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('read-all-task-manager')) {
            return true;
        }

        if (! $user->can('read-task-manager')) {
            return false;
        }

        return $this->isOwnOrAssigned($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->can('create-task-manager');
    }

    public function update(User $user, Task $task): bool
    {
        if ($task->is_private && ! $this->isOwnOrAssigned($user, $task)) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('update-any-task-manager')) {
            return true;
        }

        return $user->can('update-task-manager') && $this->isOwnOrAssigned($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($task->is_private && ! $this->isOwnOrAssigned($user, $task)) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        return $user->can('delete-task-manager') && (int) $task->created_by_user_id === (int) $user->id;
    }

    public function changeStatus(User $user, Task $task): bool
    {
        return $this->view($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('change-status-task-manager')
        );
    }

    // Distinct from changeStatus() — that one covers moving a task between
    // To Do/In Progress/Review; actually completing it (or un-completing
    // it) needs this instead, since "can reshuffle the board" and "can
    // close out work" are different levels of trust.
    public function markComplete(User $user, Task $task): bool
    {
        return $this->view($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('mark-complete-task-manager')
        );
    }

    public function assign(User $user, Task $task): bool
    {
        // Blocks a non-owner/non-assignee from self-assigning their way
        // into a private task's visibility, same exclusivity as view().
        if ($task->is_private && ! $this->isOwnOrAssigned($user, $task)) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('assign-task-manager');
    }

    public function assignMultiple(User $user, Task $task): bool
    {
        return $this->assign($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('assign-multiple-task-manager')
        );
    }

    public function reassign(User $user, Task $task): bool
    {
        return $this->assign($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('reassign-task-manager')
        );
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('comment-task-manager')
        );
    }

    public function attach(User $user, Task $task): bool
    {
        return $this->view($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('attach-task-manager')
        );
    }

    public function manageChecklist(User $user, Task $task): bool
    {
        return $this->view($user, $task) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('manage-checklist-task-manager')
        );
    }

    public function manageSettings(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('manage-task-settings');
    }

    public function viewReports(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('view-reports-task-manager');
    }

    public function printReports(User $user): bool
    {
        return $this->viewReports($user) && (
            $user->hasRole(['Super Admin', 'Admin']) || $user->can('print-reports-task-manager')
        );
    }

    protected function isOwnOrAssigned(User $user, Task $task): bool
    {
        if ((int) $task->created_by_user_id === (int) $user->id) {
            return true;
        }

        return $task->assignees()
            ->where('assignee_type', User::class)
            ->where('assignee_id', $user->id)
            ->exists();
    }
}
