<?php

namespace App\Listeners;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;

class CaptureFailedLoginAttempt
{
    public function handle(Failed $event): void
    {
        $request = request();
        $ua = (string) $request->userAgent();
        $agent = user_agent_info($ua);

        $username = (string) ($event->credentials['username'] ?? $event->credentials['email'] ?? '');

        $user = $event->user;
        if (! $user && $username !== '') {
            $user = User::withoutGlobalScopes()
                ->where('username', $username)
                ->orWhere('email', $username)
                ->first();
        }

        LoginLog::query()->create([
            'account_id' => optional($user)->account_id ?: getAccountId(1),
            'user_id' => optional($user)->id,
            'username' => $username !== '' ? $username : optional($user)->username,
            'role' => $user?->roles?->first()?->name,
            'status' => 'failed',
            'ip_address' => $request->ip(),
            'device' => $agent['device'],
            'os' => $agent['os'],
            'browser' => $agent['browser'],
            'location' => geo_from_ip($request->ip()),
            'user_agent' => $ua,
            'error_message' => 'Invalid credentials',
            'attempted_at' => now(),
        ]);

        // too many wrong sign-ins from one address block that address (10 minutes, then longer; Super Admins can whitelist an address)
        \App\Services\Security\AccessGuard::recordLoginFailure((string) $request->ip(), 'password');
    }
}
