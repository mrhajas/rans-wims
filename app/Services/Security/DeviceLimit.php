<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * How many devices one person may be signed in on at the same time (Settings, Super Admin; default 3, 0 = no limit).
 *
 * A "device" is one login session. The session that signs in is admitted when fewer than the limit of the person's OTHER devices are live;
 * otherwise it is held (pending) on the "Too many devices" page where the person signs out one device, several, or all, and carries on.
 * Devices already signed in are never thrown out when the limit is lowered; the limit applies to new sign-ins.
 * Live = a row in the session table that is not older than the session lifetime. Needs the database session driver.
 */
class DeviceLimit
{
    public const KEY = 'max_devices_per_user';

    public const DEFAULT = 3;

    public const MAX = 50;

    /** The limit now: 0 means no limit. */
    public static function limit(): int
    {
        $v = get_settings(self::KEY);

        return ($v === null || $v === '') ? self::DEFAULT : max(0, min(self::MAX, (int) $v));
    }

    public static function enabled(): bool
    {
        return config('session.driver') === 'database';
    }

    private static function db()
    {
        return DB::connection(config('session.connection'));
    }

    private static function sessions(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private static function cutoff(): int
    {
        return now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();
    }

    /** An opaque handle for a session, so the page never carries a real session id (a session id is a secret). */
    public static function token(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 24);
    }

    /** The person's OTHER admitted, still live devices. */
    private static function liveOthers(int $userId, string $exceptSession)
    {
        return self::db()->table('user_devices as d')->join(self::sessions().' as s', 's.id', '=', 'd.session_id')
            ->where('d.user_id', $userId)->where('d.admitted', true)->where('s.user_id', $userId)->where('s.last_activity', '>=', self::cutoff())
            ->where('d.session_id', '!=', $exceptSession);
    }

    /** Decide this session: 'ok' (admitted) or 'pending' (the limit is reached). Safe to call again; it also keeps the device record current. */
    public static function admit(Request $request, User $user): string
    {
        $sid = $request->session()->getId();
        $state = self::db()->transaction(function () use ($request, $user, $sid) {
            self::db()->table('users')->where('id', $user->id)->lockForUpdate()->value('id'); // one admission at a time for this person
            $limit = self::limit();
            $admit = $limit === 0 || self::liveOthers((int) $user->id, $sid)->count() < $limit;
            $now = now();
            $row = self::db()->table('user_devices')->where('session_id', $sid)->first();
            $data = ['user_id' => $user->id, 'admitted' => $admit, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000), 'last_seen_at' => $now];
            if ($row) {
                self::db()->table('user_devices')->where('id', $row->id)->update($data);
            } else {
                self::db()->table('user_devices')->insert($data + ['session_id' => $sid, 'first_seen_at' => $now]);
            }

            return $admit ? 'ok' : 'pending';
        });
        $request->session()->put(['device_limit_state' => $state, 'device_limit_sid' => $sid, 'device_limit_touched' => time()]);

        return $state;
    }

    /** An admitted session is touched at most once a minute; a session id that changed (a sign-in step regenerates it) is carried over. */
    public static function touch(Request $request, User $user): string
    {
        $sid = $request->session()->getId();
        $old = (string) $request->session()->get('device_limit_sid', '');
        if ($old !== $sid) {
            self::db()->table('user_devices')->where('session_id', $old)->where('user_id', $user->id)->update(['session_id' => $sid]);
            $request->session()->put('device_limit_sid', $sid);
        }
        if (time() - (int) $request->session()->get('device_limit_touched', 0) >= 60) {
            $n = self::db()->table('user_devices')->where('session_id', $sid)->update(['last_seen_at' => now(), 'ip_address' => $request->ip()]);
            $request->session()->put('device_limit_touched', time());
            if (! $n) {
                return self::admit($request, $user); // the record was lost: decide again
            }
        }

        return 'ok';
    }

    /** The person's live sessions for a screen (the current one first). */
    public static function devices(Request $request, bool $admittedOnly = false): array
    {
        $uid = (int) $request->user()->id;
        $current = $request->session()->getId();
        $rows = self::db()->table(self::sessions().' as s')->leftJoin('user_devices as d', 'd.session_id', '=', 's.id')
            ->where('s.user_id', $uid)->where('s.last_activity', '>=', self::cutoff())
            ->when($admittedOnly, fn ($q) => $q->where(fn ($w) => $w->where('d.admitted', true)->orWhere('s.id', $current)))
            ->orderByDesc('s.last_activity')->get(['s.id', 's.ip_address', 's.user_agent', 's.last_activity', 'd.admitted', 'd.first_seen_at']);
        $out = [];
        foreach ($rows as $r) {
            $info = user_agent_info((string) $r->user_agent);
            $last = Carbon::createFromTimestamp((int) $r->last_activity);
            $out[] = [
                'token' => self::token($r->id), 'current' => $r->id === $current, 'platform' => $info['os'], 'device' => $info['device'] ?? '', 'browser' => $info['browser'],
                'mobile' => (bool) preg_match('/android|iphone|ipad|mobile/i', (string) $r->user_agent), 'ip' => $r->ip_address, 'online' => $last->gt(now()->subMinutes(5)),
                'last_active' => $last->diffForHumans(), 'since' => $r->first_seen_at ? Carbon::parse($r->first_seen_at)->toDateTimeString() : null,
            ];
        }
        usort($out, fn ($a, $b) => (int) $b['current'] <=> (int) $a['current']);

        return $out;
    }

    /** Sign ONE other device out (never the one in use: that is "log out"). Returns false when the handle matches nothing of this person's. */
    public static function signOut(Request $request, string $token): bool
    {
        $uid = (int) $request->user()->id;
        $current = $request->session()->getId();
        foreach (self::db()->table(self::sessions())->where('user_id', $uid)->pluck('id') as $id) {
            if ($id !== $current && hash_equals(self::token($id), $token)) {
                self::db()->table(self::sessions())->where('id', $id)->where('user_id', $uid)->delete();
                self::db()->table('user_devices')->where('session_id', $id)->delete();
                log_activity('Device signed out', ['by' => 'the person', 'user' => $request->user()->name], $request->user(), 'Security');

                return true;
            }
        }

        return false;
    }

    /** Sign every OTHER device out; the "remember me" sign-in of every device ends too. Returns how many sessions were closed. */
    public static function signOutOthers(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $current = $request->session()->getId();
        $n = self::db()->table(self::sessions())->where('user_id', $uid)->where('id', '!=', $current)->delete();
        self::db()->table('user_devices')->where('user_id', $uid)->where('session_id', '!=', $current)->delete();
        self::forgetRemembered($request->user());
        log_activity('All other devices signed out', ['closed' => $n, 'user' => $request->user()->name], $request->user(), 'Security');

        return (int) $n;
    }

    /** Sign every device out, this one too (the caller then logs this session out). */
    public static function signOutAll(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $n = self::db()->table(self::sessions())->where('user_id', $uid)->delete();
        self::db()->table('user_devices')->where('user_id', $uid)->delete();
        self::forgetRemembered($request->user());
        log_activity('All devices signed out', ['closed' => $n, 'user' => $request->user()->name], $request->user(), 'Security');

        return (int) $n;
    }

    /** A new "remember me" token, so a device that was signed out cannot sign itself back in from its cookie. */
    private static function forgetRemembered(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['remember_token' => Str::random(60)]);
    }

    /** The device record of a session that logged out. */
    public static function release(string $sessionId): void
    {
        if (self::enabled() && $sessionId !== '') {
            self::db()->table('user_devices')->where('session_id', $sessionId)->delete();
        }
    }
}
