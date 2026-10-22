<?php

namespace App\Services\Prayer;

use App\Models\PrayerMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\FeatureManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Prayer times, the shutdown alert before each prayer, the prayer notice and the lock that follows. Everything adjustable is in
 * `config()` (stored as JSON in the settings table, edited at Prayer Times > Settings) - nothing here is hard-wired.
 *
 *   alert  : alert_before_min before the prayer      "WIMS will lock at HH:MM for <prayer>"
 *   prayer : notice_before_min before the lock       prayer notice with a verse or hadith, the adhan plays
 *   locked : lock_start_after_min after the prayer   (0 = at the prayer time) everyone in scope is signed out; nobody in scope can sign in
 *   ...until lock_minutes later
 *
 * 'shutdown' = false (Alert Only): the alert and the prayer notice still show, but there is no locked phase, so nobody is signed out.
 */
class PrayerService
{
    public const KEY = 'prayer_config';
    public const PRAYERS = ['fajr' => 'Fajr', 'dhuhr' => 'Dhuhr', 'asr' => 'Asr', 'maghrib' => 'Maghrib', 'isha' => 'Isha'];

    public const DEFAULTS = [
        'enabled' => false,
        'city' => 'Zanzibar',
        'lat' => -6.1659,
        'lng' => 39.2026,
        'elevation' => 0,
        'timezone' => 'Africa/Dar_es_Salaam',
        'method' => 'MWL',
        'asr' => 1,                       // 1 = Shafi'i, Maliki, Hanbali; 2 = Hanafi
        'adjust' => ['fajr' => 0, 'sunrise' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0],
        'prayers' => ['fajr' => true, 'dhuhr' => true, 'asr' => true, 'maghrib' => true, 'isha' => true],
        'alert_before_min' => 10,         // the shutdown alert starts this long before the prayer
        'notice_before_min' => 3,         // the prayer notice and the adhan start when this many minutes are left before the lock
        'lock_start_after_min' => 0,      // 0: WIMS locks at the prayer time
        'lock_minutes' => 15,
        'shutdown' => true,               // true: Alert and Shutdown (WIMS locks). false: Alert Only (the alert and notice show, nobody is signed out)
        'all_users' => true,
        'user_ids' => [],
        'excluded_user_ids' => [],
        'exempt_super_admins' => false,
        'adhan_enabled' => true,
        'adhan' => 'aaqib',
        'adhan_volume' => 70,
        'show_in_topbar' => true,
        'show_on_dashboard' => true,
    ];

    public const ADHANS = [
        'aaqib' => ['label' => 'Adhan, Aaqib Azeez', 'file' => '/audio/adhan/adhan-aaqib-azeez.mp3', 'note' => 'Modern, clear voice. Recording by Aaqib Azeez, CC BY-SA 4.0 (Wikimedia Commons).'],
        'sabah' => ['label' => 'Adhan, Sabah Fakhri', 'file' => '/audio/adhan/adhan-sabah-fakhri.mp3', 'note' => 'Classical Aleppo style by Sabah Fakhri. Public domain (Wikimedia Commons).'],
    ];

    /** The adhan every user falls back to (and the one chosen when the active adhan is deleted). */
    public const FALLBACK_ADHAN = 'sabah';

    private static ?array $cfg = null;

    /** All adhans: the two built-in ones, then the uploaded ones (key "custom-{id}"). @return array<string, array{label:string,file:string,note:string,custom:bool,id?:int}> */
    public static function adhans(): array
    {
        static $memo = null; // kept for three seconds: a page asks for the adhans more than once
        if ($memo !== null && $memo[0] > microtime(true)) {
            return $memo[1];
        }
        $all = [];
        foreach (self::ADHANS as $k => $a) {
            $all[$k] = $a + ['custom' => false];
        }
        try {
            if (\App\Support\SchemaCache::hasTable('prayer_adhans')) {
                foreach (\App\Models\PrayerAdhan::query()->orderBy('id')->get() as $a) {
                    $all[$a->key()] = ['label' => $a->name, 'file' => '/'.ltrim($a->file, '/'), 'note' => 'Added by you'.($a->original_name ? ' ('.$a->original_name.')' : '').'.', 'custom' => true, 'id' => $a->id];
                }
            }
        } catch (\Throwable) {
            // the table is not there yet: only the built-in adhans
        }
        $memo = [microtime(true) + 3, $all];

        return $all;
    }

    /**
     * What the browser is given to play. An uploaded recording whose file is not on this server (a database copied without its audio files, or a file removed by hand)
     * would only answer "not found" and the player would blame the browser, so the built-in adhan plays instead and the page says why.
     *
     * @return array{url:string,label:string,missing:bool,wanted:?string}
     */
    public static function playable(array $a): array
    {
        if (is_file(public_path(ltrim((string) $a['file'], '/')))) {
            return ['url' => $a['file'], 'label' => $a['label'], 'missing' => false, 'wanted' => null];
        }
        $fb = self::ADHANS[self::FALLBACK_ADHAN];

        return ['url' => $fb['file'], 'label' => $fb['label'], 'missing' => true, 'wanted' => $a['label']];
    }

    /** The chosen adhan, or the built-in default when the key is unknown (for example after it was deleted). */
    public static function adhan(?string $key): array
    {
        $all = self::adhans();

        return $all[$key] ?? self::ADHANS[self::FALLBACK_ADHAN];
    }

    public static function flush(): void
    {
        self::$cfg = null;
    }

    public static function config(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $saved = [];
        try {
            $raw = get_settings(self::KEY);
            $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        } catch (\Throwable) {
            $saved = [];
        }
        $c = array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        foreach (['adjust', 'prayers'] as $k) {
            $c[$k] = array_replace(self::DEFAULTS[$k], is_array($saved[$k] ?? null) ? $saved[$k] : []);
        }

        return self::$cfg = $c;
    }

    public static function save(array $input, int $accountId): array
    {
        $c = array_replace(self::config(), $input);
        Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($c)]);
        self::flush();

        return self::config();
    }

    /** Is the module on: the Global Feature switch AND the Prayer Times switch AND its table exists. */
    public static function enabled(): bool
    {
        return FeatureManager::isEnabled('prayer_times') && ! empty(self::config()['enabled']) && \App\Support\SchemaCache::hasTable('prayer_messages');
    }

    public static function inScope(?User $user): bool
    {
        if (! $user || ! self::enabled()) {
            return false;
        }
        $c = self::config();
        if (! empty($c['exempt_super_admins']) && $user->hasRole('Super Admin')) {
            return false;
        }
        $id = (int) $user->id;
        if (in_array($id, array_map('intval', $c['excluded_user_ids'] ?? []), true)) {
            return false;
        }

        return ! empty($c['all_users']) || in_array($id, array_map('intval', $c['user_ids'] ?? []), true);
    }

    private static function calc(): PrayerTimes
    {
        $c = self::config();

        return new PrayerTimes((float) $c['lat'], (float) $c['lng'], (string) $c['timezone'], (string) $c['method'], (int) $c['asr'] === 2 ? 2 : 1, (float) $c['elevation']);
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::config()['timezone']);
    }

    /** @return array<string, CarbonImmutable> the day's six times with the manual minute adjustments applied */
    public static function timesFor(CarbonImmutable $day): array
    {
        $adj = self::config()['adjust'];
        $out = [];
        foreach (self::calc()->forDate($day) as $name => $t) {
            $out[$name] = $t->addMinutes((int) ($adj[$name] ?? 0));
        }

        return $out;
    }

    /**
     * The prayers around now (yesterday's last, today's, tomorrow's first), each with its alert / prayer / lock / unlock moments,
     * oldest first. Only the prayers switched on are included.
     */
    public static function events(CarbonImmutable $now): array
    {
        $c = self::config();
        $events = [];
        foreach ([-1, 0, 1] as $off) {
            $day = $now->addDays($off)->startOfDay();
            foreach (self::timesFor($day) as $key => $at) {
                if (! isset(self::PRAYERS[$key]) || empty($c['prayers'][$key])) {
                    continue;
                }
                // minutes in the settings (whole numbers); counted in seconds here so a short test cycle can use fractions
                $lockAt = $at->addSeconds((int) round($c['lock_start_after_min'] * 60));
                $alertAt = $at->subSeconds((int) round($c['alert_before_min'] * 60));
                // the notice cannot start before the alert nor after the lock
                $noticeAt = $lockAt->subSeconds((int) round($c['notice_before_min'] * 60));
                $noticeAt = $noticeAt < $alertAt ? $alertAt : ($noticeAt > $lockAt ? $lockAt : $noticeAt);
                $events[] = [
                    'key' => $key, 'label' => self::PRAYERS[$key], 'at' => $at,
                    'alert_at' => $alertAt, 'notice_at' => $noticeAt,
                    'lock_at' => $lockAt,
                    // Alert Only: the lock never starts, so there is no locked phase and nobody is signed out
                    'unlock_at' => empty($c['shutdown']) ? $lockAt : $lockAt->addSeconds((int) round($c['lock_minutes'] * 60)),
                ];
            }
        }
        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $events;
    }

    /** Where we are right now: none | alert | prayer | locked, with the prayer it is about. */
    public static function phase(?CarbonImmutable $now = null): array
    {
        $now ??= self::now();
        $next = null;
        foreach (self::events($now) as $e) {
            if ($now >= $e['lock_at'] && $now < $e['unlock_at']) {
                return ['phase' => 'locked'] + $e;
            }
            if ($now >= $e['notice_at'] && $now < $e['lock_at']) {
                return ['phase' => 'prayer'] + $e;
            }
            if ($now >= $e['alert_at'] && $now < $e['notice_at']) {
                return ['phase' => 'alert'] + $e;
            }
            if (! $next && $e['at'] > $now) {
                $next = $e;
            }
        }

        return ['phase' => 'none'] + ($next ?? []);
    }

    /** Hard-coded: Dr Hajas (the Licence Manager) is never locked out by a prayer break and can sign in at any time. */
    public static function exempt(?User $user): bool
    {
        return $user !== null && \App\Services\Licensing\LicenceService::isManagerOrActingManager($user);
    }

    /** The lock that applies to this user right now, or null. */
    public static function lockFor(?User $user): ?array
    {
        if (\App\Support\Simulation\Environment::isSimulation()) {
            return null;
        }
        if (self::exempt($user) || ! self::inScope($user)) {
            return null;
        }
        $p = self::phase();

        return $p['phase'] === 'locked' ? $p : null;
    }

    /** Verse or saying for a prayer on a day: alternates verse / hadith through the day, different each day. */
    public static function messageFor(string $prayer, CarbonImmutable $day): ?array
    {
        try {
            // every active message is read once per request (this runs for each of the day's prayers: four identical queries a page); the prayer is picked here
            static $memo = null;
            if ($memo === null || $memo[0] < microtime(true)) {
                $memo = [microtime(true) + 3, PrayerMessage::query()->where('is_active', true)->orderBy('id')->get()];
            }
            $all = $memo[1]->filter(fn ($m) => in_array($m->prayer, ['any', $prayer], true))->values();
        } catch (\Throwable) {
            return null;
        }
        if ($all->isEmpty()) {
            return null;
        }
        $idx = array_search($prayer, array_keys(self::PRAYERS), true) ?: 0;
        $wantKind = (($day->dayOfYear + $idx) % 2 === 0) ? 'ayah' : 'hadith';
        $pool = $all->where('kind', $wantKind);
        // a message for this very prayer is preferred over a general one
        $specific = $pool->where('prayer', $prayer);
        $pool = $specific->isNotEmpty() && ($day->dayOfYear % 2 === 0) ? $specific : ($pool->isNotEmpty() ? $pool : $all);
        $m = $pool->values()[(($day->dayOfYear * 5) + $idx * 3) % $pool->count()];

        return ['kind' => $m->kind, 'arabic' => $m->arabic, 'text' => $m->text_en, 'text_sw' => $m->text_sw, 'reference' => $m->reference];
    }

    /** Everything the browser needs (it keeps its own clock): shared with every page for people in scope or who may see the times. */
    public static function share(?User $user): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $c = self::config();
        $now = self::now();
        $today = $now->startOfDay();
        $events = self::events($now);

        $times = [];
        foreach (self::timesFor($today) as $key => $at) {
            $times[] = ['key' => $key, 'label' => $key === 'sunrise' ? 'Sunrise' : self::PRAYERS[$key], 'time' => $at->format('H:i'), 'at' => $at->toIso8601String(), 'is_prayer' => isset(self::PRAYERS[$key])];
        }

        $upcoming = [];
        foreach ($events as $e) {
            if ($e['unlock_at'] > $now && count($upcoming) < 4) {
                $upcoming[] = [
                    'key' => $e['key'], 'label' => $e['label'], 'at' => $e['at']->toIso8601String(), 'alert_at' => $e['alert_at']->toIso8601String(), 'notice_at' => $e['notice_at']->toIso8601String(),
                    'lock_at' => $e['lock_at']->toIso8601String(), 'unlock_at' => $e['unlock_at']->toIso8601String(), 'time' => $e['at']->format('H:i'),
                    'message' => self::messageFor($e['key'], $e['at']->startOfDay()),
                ];
            }
        }

        $adhan = self::playable(self::adhan($c['adhan']));

        return [
            'server_now' => $now->toIso8601String(),
            'timezone' => $c['timezone'], 'city' => $c['city'],
            'shutdown' => ! empty($c['shutdown']),
            'in_scope' => self::inScope($user),
            'exempt' => self::exempt($user),
            'times' => $times, 'upcoming' => $upcoming,
            'alert_before_min' => (int) $c['alert_before_min'], 'notice_before_min' => (int) $c['notice_before_min'], 'lock_start_after_min' => (int) $c['lock_start_after_min'], 'lock_minutes' => (int) $c['lock_minutes'],
            'adhan' => ['enabled' => (bool) $c['adhan_enabled'], 'url' => $adhan['url'], 'volume' => (int) $c['adhan_volume'], 'label' => $adhan['label'], 'missing' => $adhan['missing'], 'wanted' => $adhan['wanted']],
            'topbar' => (bool) $c['show_in_topbar'], 'dashboard' => (bool) $c['show_on_dashboard'],
        ];
    }

    /** Today's prayer times for the sign-in page (the strip at the top right), or null when the module is off. */
    public static function loginTimes(): ?array
    {
        $s = self::share(null);

        return $s ? array_intersect_key($s, array_flip(['server_now', 'timezone', 'city', 'times', 'upcoming'])) : null;
    }

    /** What the sign-in page shows while prayer lock is on. */
    public static function loginNotice(): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $p = self::phase();
        if ($p['phase'] !== 'locked') {
            return null;
        }
        $c = self::config();
        $adhan = self::playable(self::adhan($c['adhan']));

        return [
            'prayer' => $p['label'], 'unlock_at' => $p['unlock_at']->toIso8601String(), 'unlock_label' => $p['unlock_at']->format('h:i A'),
            'server_now' => self::now()->toIso8601String(), 'message' => self::messageFor($p['key'], $p['at']->startOfDay()),
            'adhan' => ['enabled' => (bool) $c['adhan_enabled'], 'url' => $adhan['url'], 'volume' => (int) $c['adhan_volume']],
        ];
    }
}
