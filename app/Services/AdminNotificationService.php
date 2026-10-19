<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AppNotification;

class AdminNotificationService
{
    public static function send(string $title, string $message, string $url, array $extra = []): void
    {
        $payload = array_merge([
            'module' => 'admin_operations',
            'type' => 'admin_operation',
            'title' => $title,
            'message' => $message,
            'url' => $url,
            'created_at' => now()->toDateTimeString(),
        ], $extra);

        $admins = User::role(['Super Admin', 'Admin'])->get();

        foreach ($admins as $admin) {
            $admin->notify(new AppNotification($payload));
        }
    }
}
