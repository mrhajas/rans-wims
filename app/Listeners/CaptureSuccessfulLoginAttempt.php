<?php

namespace App\Listeners;

use App\Models\LoginLog;
use Illuminate\Auth\Events\Login;

class CaptureSuccessfulLoginAttempt
{
    public function handle(Login $event): void
    {
        $request = request();
        $ua = (string) $request->userAgent();
        $agent = user_agent_info($ua);

        // Read exactly once by HandleInertiaRequests on the very next
        // request (the post-login redirect) — Laravel's flash data is
        // discarded after that regardless, so the gate-opening animation on
        // the dashboard can only ever play right after a fresh login, never
        // on an ordinary page visit.
        session()->flash('just_logged_in', true);

        // a successful sign-in starts the count of sign-in codes again
        if ($event->user) {
            \App\Services\Security\AccessGuard::otpReset((int) $event->user->id);
        }

        // Not flash — needs to survive every request for the rest of this
        // browser session, unlike just_logged_in above. Drives the "Logged
        // In Time" clock in the Attendance panel (Navbar.vue), which counts
        // up from this session's login rather than from any attendance
        // clock-in.
        session()->put('current_login_at', now()->toIso8601String());

        LoginLog::query()->create([
            'account_id' => $event->user->account_id ?: getAccountId(1),
            'user_id' => $event->user->id,
            'username' => $event->user->username ?: $event->user->email,
            'role' => optional($event->user->roles->first())->name,
            'status' => 'success',
            'ip_address' => $request->ip(),
            'device' => $agent['device'],
            'os' => $agent['os'],
            'browser' => $agent['browser'],
            'location' => geo_from_ip($request->ip()),
            'user_agent' => $ua,
            'attempted_at' => now(),
        ]);
    }
}
