<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController;

/**
 * Profile page with an honest "Browser Sessions" list.
 *
 * Jetstream lists every row in the sessions table for the user, including sessions that expired long ago (Laravel only
 * garbage-collects them on a lottery) and one row per past login. Here only sessions that are still valid are shown, every one of them
 * (each is a device that counts toward the devices-per-user limit), and recent activity is flagged as online.
 */
class ProfileSessionsController extends UserProfileController
{
    private const ONLINE_MINUTES = 5;

    public function sessions(Request $request)
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $table = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
        $cutoff = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();
        $userId = $request->user()->getAuthIdentifier();
        $currentId = $request->session()->getId();

        // Drop this user's expired rows right away so they cannot pile up.
        (clone $table)->where('user_id', $userId)->where('last_activity', '<', $cutoff)->where('id', '!=', $currentId)->delete();

        $rows = (clone $table)->where('user_id', $userId)->where('last_activity', '>=', $cutoff)->orderByDesc('last_activity')->get();
        $kept = $rows->sortByDesc(fn ($r) => $r->id === $currentId ? PHP_INT_MAX : $r->last_activity)->values();

        return $kept->map(function ($session) use ($currentId) {
            $agent = $this->createAgent($session);
            $last = Carbon::createFromTimestamp($session->last_activity);

            return (object) [
                'agent' => [
                    'is_desktop' => $agent->isDesktop(),
                    'platform' => $agent->platform(),
                    'browser' => $agent->browser(),
                ],
                'token' => \App\Services\Security\DeviceLimit::token($session->id),
                'ip_address' => $session->ip_address,
                'is_current_device' => $session->id === $currentId,
                'is_online' => $last->gt(now()->subMinutes(self::ONLINE_MINUTES)),
                'last_active' => $last->diffForHumans(),
            ];
        });
    }
}
