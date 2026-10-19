<?php

namespace App\Services;

use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * User account alerts. Who is told, on which channels and with which words is set on the Users workflow page (users/workflow);
 * this class says which event happened. $subject is the user the notice is about (counted as "the owner" of the record).
 */
class UserNotifier
{
    public static function fire(string $event, User $subject, string $title, string $message, ?User $actor = null, array $values = []): void
    {
        $actor ??= auth()->user();
        try {
            $url = route('users.edit', $subject->id);
        } catch (\Throwable) {
            $url = null;
        }

        WorkflowMessenger::dispatch('users', $event, $subject, $actor, [
            'account_id' => (int) ($subject->account_id ?: ($actor?->account_id ?? 1)),
            'title' => $title, 'message' => $message, 'url' => $url,
            'owner_user_id' => (int) $subject->id,
            'dedupe' => $subject->id . '|' . $event . '|' . intdiv(time(), 20),
            'values' => $values + [
                'user' => (string) $subject->name, 'username' => (string) $subject->username,
                'roles' => $subject->roles()->pluck('name')->implode(', '),
            ],
        ]);
    }

    /** A change on the Roles screen. The role is the record; there is no single person the notice is about. */
    public static function fireRole(string $event, \App\Models\Role $role, string $title, string $message, ?User $actor = null, array $values = []): void
    {
        $actor ??= auth()->user();
        try {
            $url = $event === 'role_deleted' ? route('roles.index') : route('roles.edit', $role->id);
        } catch (\Throwable) {
            $url = null;
        }

        try {
            $permissions = $role->permissions()->count();
            $people = User::role($role->name)->where('is_active', true)->count();
        } catch (\Throwable) {
            $permissions = $people = 0;
        }

        WorkflowMessenger::dispatch('users', $event, $role, $actor, [
            'account_id' => (int) ($actor?->account_id ?? $role->account_id ?? 1),
            'title' => $title, 'message' => $message, 'url' => $url,
            'dedupe' => 'role|' . $role->id . '|' . $event . '|' . intdiv(time(), 20),
            'values' => $values + ['role' => (string) $role->name, 'permissions' => (string) $permissions, 'people' => (string) $people],
        ]);
    }
}
