<?php

namespace App\Services\AiTools;

use App\Models\LoginLog;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class UserActivityTool
{
    use ResolvesEntities;

    public function lastLogin(array $input, User $user): array
    {
        $userName = $input['user_name'] ?? null;
        $target = $this->resolveUser($userName);

        if (! $target) {
            return ['found' => false, 'message' => "No user matching \"{$userName}\" was found."];
        }

        if ($target->id !== $user->id && ! $this->userMayViewOthers($user)) {
            return ['error' => 'not_permitted', 'message' => "You do not have permission to view another user's login history."];
        }

        $log = LoginLog::ofAccount()
            ->where('user_id', $target->id)
            ->where('status', 'success')
            ->latest('attempted_at')
            ->first();

        if (! $log) {
            return ['found' => false, 'message' => "{$target->name} has no recorded successful logins."];
        }

        return [
            'found' => true,
            'user' => $target->name,
            'last_login_at' => optional($log->attempted_at)->format('Y-m-d H:i'),
            'ip_address' => $log->ip_address,
            'device' => $log->device,
        ];
    }
}
