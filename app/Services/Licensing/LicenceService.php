<?php

namespace App\Services\Licensing;

use App\Models\Licence;
use App\Models\LicenceEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Everything about WIMS licences in one place: who may issue them (hard-coded to Dr Hajas), how a 128-character key is
 * built and checked, the encrypted licence file, which licence is current, and whether people may use WIMS right now.
 *
 * Key = 96 random characters + a 32-character signature of the licence details (so a key cannot be edited or reused
 * for different dates or another licensee). Alphabet: A-Z and 2-7.
 */
class LicenceService
{
    public const KEY_LENGTH = 128;

    public const FILE_HEADER = 'WIMSLIC1';

    public const FILE_EXTENSION = 'wimslic';

    public const CONTACT = 'the Technical Team (Extrion Tech, info@extrion.co.tz, +255 654 120308)';

    /** The only person who can open the Licence Manager. Hard-coded on purpose; nothing in the Roles screens can grant it. */
    private const MANAGER_EMAILS = ['extriontech@gmail.com'];

    private const MANAGER_NAMES = ['drhajas'];

    private const MANAGER_USER_ID = 1;

    private static ?array $currentCache = null;

    private static bool $currentLoaded = false;

    /* ------------------------------------------------------------------ who may manage */

    /**
     * True only for Dr Hajas, and only when he is really signed in (a Super Admin using "Login as user" on his account does
     * not inherit the Licence Manager).
     */
    public static function isManager(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (class_exists(\App\Support\Impersonation::class) && \App\Support\Impersonation::isActive()) {
            return false;
        }
        $name = strtolower(trim((string) $user->name));
        $email = strtolower(trim((string) $user->email));

        return in_array($email, self::MANAGER_EMAILS, true)
            || ($user->id === self::MANAGER_USER_ID && in_array($name, self::MANAGER_NAMES, true));
    }

    /** Dr Hajas, even while impersonating someone else (the real person behind the session). Used so he is never locked out. */
    public static function isManagerOrActingManager(?User $user): bool
    {
        if (self::isManager($user)) {
            return true;
        }
        if (class_exists(\App\Support\Impersonation::class) && \App\Support\Impersonation::isActive()) {
            $original = \App\Support\Impersonation::originalUser();

            return $original && self::isManagerIdentity($original);
        }

        return false;
    }

    private static function isManagerIdentity(User $user): bool
    {
        return in_array(strtolower(trim((string) $user->email)), self::MANAGER_EMAILS, true)
            || ($user->id === self::MANAGER_USER_ID && in_array(strtolower(trim((string) $user->name)), self::MANAGER_NAMES, true));
    }

    /* ------------------------------------------------------------------ keys and files */

    private static function secret(): string
    {
        return 'wims-licence|'.(string) config('app.key');
    }

    private static function base32(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** @param array{licence_number: string, licensee: string, starts_at: string, expires_at: string} $f */
    private static function signature(string $body, array $f): string
    {
        $data = implode('|', [$body, $f['licence_number'], mb_strtoupper(trim($f['licensee'])), $f['starts_at'], $f['expires_at']]);

        return substr(self::base32(hash_hmac('sha256', $data, self::secret(), true)), 0, 32);
    }

    /** A fresh 128-character key for these details. */
    public static function makeKey(array $f): string
    {
        $body = substr(self::base32(random_bytes(64)), 0, 96);

        return $body.self::signature($body, $f);
    }

    /** Upper-case, spaces / dashes / line breaks removed. */
    public static function normaliseKey(string $key): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    public static function keyMatchesDetails(string $key, array $f): bool
    {
        $key = self::normaliseKey($key);

        return strlen($key) === self::KEY_LENGTH && hash_equals(self::signature(substr($key, 0, 96), $f), substr($key, 96));
    }

    private static function detailsOf(Licence $l): array
    {
        return ['licence_number' => $l->licence_number, 'licensee' => $l->licensee, 'starts_at' => $l->starts_at->toDateString(), 'expires_at' => $l->expires_at->toDateString()];
    }

    /** The encrypted licence file contents (what Dr Hajas sends to the Super Admins). */
    public static function fileContents(Licence $l): string
    {
        $payload = [
            'v' => 1,
            'key' => $l->getRawOriginal('key'),
            'licence_number' => $l->licence_number,
            'licensee' => $l->licensee,
            'edition' => $l->edition,
            'licence_type' => $l->licence_type,
            'starts_at' => $l->starts_at->toDateString(),
            'expires_at' => $l->expires_at->toDateString(),
            'max_users' => $l->max_users,
            'meta' => $l->meta,
            'notes' => $l->notes,
            'issued_at' => optional($l->issued_at)->toIso8601String(),
        ];

        return self::FILE_HEADER."\n".chunk_split(base64_encode(Crypt::encryptString(json_encode($payload))), 76, "\n");
    }

    /**
     * Reads an uploaded / pasted licence: either the 128-character key or the whole encrypted file text.
     * Returns the stored licence (created from the file when this system had not seen it yet).
     */
    public static function resolve(string $input, ?User $by = null): Licence
    {
        $input = trim($input);
        if ($input === '') {
            throw LicenceRejected::because('empty', __('Paste a licence key or choose a licence file.'));
        }

        if (str_contains($input, self::FILE_HEADER)) {
            return self::fromFile($input, $by);
        }

        $key = self::normaliseKey($input);
        if (strlen($key) !== self::KEY_LENGTH) {
            throw LicenceRejected::because('length', __('A WIMS licence key has exactly :n characters. This one has :got.', ['n' => self::KEY_LENGTH, 'got' => strlen($key)]));
        }
        $licence = Licence::query()->where('key', $key)->first();
        if (! $licence || ! self::keyMatchesDetails($key, self::detailsOf($licence))) {
            throw LicenceRejected::because('unknown', __('This licence key was not issued for this system or has been altered. Check it and try again, or contact the Technical Team.'));
        }

        return $licence;
    }

    private static function fromFile(string $text, ?User $by): Licence
    {
        $body = trim(str_replace(self::FILE_HEADER, '', $text));
        try {
            $payload = json_decode(Crypt::decryptString((string) base64_decode(preg_replace('/\s+/', '', $body), true)), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw LicenceRejected::because('unreadable', __('This licence file could not be read. It may be damaged, or it was made for a different system.'));
        }
        $f = ['licence_number' => (string) ($payload['licence_number'] ?? ''), 'licensee' => (string) ($payload['licensee'] ?? ''), 'starts_at' => (string) ($payload['starts_at'] ?? ''), 'expires_at' => (string) ($payload['expires_at'] ?? '')];
        $key = self::normaliseKey((string) ($payload['key'] ?? ''));
        if (! self::keyMatchesDetails($key, $f)) {
            throw LicenceRejected::because('tampered', __('This licence file does not pass the security check.'));
        }

        $existing = Licence::query()->where('key', $key)->first();
        if ($existing) {
            return $existing;
        }
        // Same licence number but a different key: the licence was edited after this file was downloaded, so the file is an old copy.
        if (Licence::query()->where('licence_number', $f['licence_number'])->exists()) {
            throw LicenceRejected::because('outdated', __('This file is an older copy of :n. That licence was edited after the file was downloaded, so the key inside no longer matches. Download the latest licence file or key from the Licence Manager.', ['n' => $f['licence_number']]));
        }

        return DB::transaction(function () use ($payload, $key, $f, $by) {
            $licence = Licence::create([
                'licence_number' => $f['licence_number'], 'key' => $key, 'licensee' => $f['licensee'],
                'edition' => $payload['edition'] ?? 'Enterprise', 'licence_type' => $payload['licence_type'] ?? 'Subscription',
                'starts_at' => $f['starts_at'], 'expires_at' => $f['expires_at'], 'max_users' => $payload['max_users'] ?? null,
                'meta' => $payload['meta'] ?? null, 'notes' => $payload['notes'] ?? null, 'status' => Licence::ISSUED,
                'issued_at' => $payload['issued_at'] ?? now(),
            ]);
            self::event($licence, 'imported', $by, __('Licence file imported into this system.'));

            return $licence;
        });
    }

    /* ------------------------------------------------------------------ issuing and changing */

    public static function nextNumber(): string
    {
        $year = now()->format('Y');
        $max = (int) Licence::query()->where('licence_number', 'like', "WIMS-LIC-{$year}-%")->get(['licence_number'])
            ->map(fn (Licence $l): int => (int) substr($l->licence_number, -4))->max();

        return sprintf('WIMS-LIC-%s-%04d', $year, $max + 1);
    }

    /** @param array<string, mixed> $d licensee, edition, licence_type, starts_at, expires_at, max_users, support_level, modules, notes */
    public static function issue(array $d, User $by): Licence
    {
        return DB::transaction(function () use ($d, $by) {
            $f = ['licence_number' => self::nextNumber(), 'licensee' => trim($d['licensee']), 'starts_at' => Carbon::parse($d['starts_at'])->toDateString(), 'expires_at' => Carbon::parse($d['expires_at'])->toDateString()];
            $licence = Licence::create([
                ...$f, 'key' => self::makeKey($f), 'edition' => $d['edition'], 'licence_type' => $d['licence_type'],
                'max_users' => $d['max_users'] ?: null, 'notes' => $d['notes'] ?? null,
                'meta' => array_filter(['support_level' => $d['support_level'] ?? null, 'modules' => $d['modules'] ?? null]) ?: null,
                'status' => Licence::ISSUED, 'issued_by_user_id' => $by->id, 'issued_at' => now(),
            ]);
            self::event($licence, 'issued', $by, __('Licence issued, valid :from to :to.', ['from' => $licence->starts_at->format('d M Y'), 'to' => $licence->expires_at->format('d M Y')]));

            return $licence;
        });
    }

    /** Makes this the licence WIMS runs on. The licence that was active before becomes Superseded. */
    public static function activate(Licence $licence, User $by): Licence
    {
        if ($licence->status === Licence::DEACTIVATED) {
            throw LicenceRejected::because('deactivated', __('This licence was deactivated by the Licence Manager. Ask the Technical Team to reactivate it or issue a new one.'));
        }
        if ($licence->expires_at->lt(today())) {
            throw LicenceRejected::because('expired', __('This licence expired on :date and cannot be activated.', ['date' => $licence->expires_at->format('d M Y')]));
        }

        return DB::transaction(function () use ($licence, $by) {
            Licence::query()->where('status', Licence::ACTIVE)->where('id', '!=', $licence->id)->get()->each(function (Licence $old) use ($by, $licence) {
                $old->update(['status' => Licence::SUPERSEDED]);
                self::event($old, 'superseded', $by, __('Replaced by :n.', ['n' => $licence->licence_number]));
            });
            $licence->update(['status' => Licence::ACTIVE, 'applied_by_user_id' => $by->id, 'applied_at' => now()]);
            self::event($licence, 'activated', $by, __('Licence activated on this system.'));
            self::flush();

            return $licence->refresh();
        });
    }

    public static function deactivate(Licence $licence, User $by, ?string $reason = null): Licence
    {
        $licence->update(['status' => Licence::DEACTIVATED, 'deactivated_by_user_id' => $by->id, 'deactivated_at' => now(), 'deactivation_reason' => $reason]);
        self::event($licence, 'deactivated', $by, $reason ?: __('Licence deactivated by the Licence Manager.'));
        self::flush();

        return $licence->refresh();
    }

    public static function reactivate(Licence $licence, User $by): Licence
    {
        Licence::query()->where('status', Licence::ACTIVE)->where('id', '!=', $licence->id)->update(['status' => Licence::SUPERSEDED]);
        $licence->update(['status' => Licence::ACTIVE, 'deactivated_by_user_id' => null, 'deactivated_at' => null, 'deactivation_reason' => null, 'applied_by_user_id' => $by->id, 'applied_at' => now()]);
        self::event($licence, 'reactivated', $by, __('Licence reactivated.'));
        self::flush();

        return $licence->refresh();
    }

    /**
     * Edits a licence and keeps its history: what changed is written to the licence's timeline. Changing the licensee or the
     * dates re-signs the key (the first 96 characters stay, the last 32 are recalculated), so the key changes with them.
     *
     * @param  array<string, mixed>  $d  licensee, edition, licence_type, starts_at, expires_at, max_users, support_level, modules, notes
     */
    public static function update(Licence $licence, array $d, User $by): Licence
    {
        return DB::transaction(function () use ($licence, $d, $by) {
            $new = [
                'licensee' => trim($d['licensee']), 'edition' => $d['edition'], 'licence_type' => $d['licence_type'],
                'starts_at' => Carbon::parse($d['starts_at'])->toDateString(), 'expires_at' => Carbon::parse($d['expires_at'])->toDateString(),
                'max_users' => $d['max_users'] ?: null, 'notes' => ($d['notes'] ?? null) ?: null,
            ];
            $meta = array_filter(['support_level' => $d['support_level'] ?? null, 'modules' => $d['modules'] ?? null]) ?: null;

            $labels = ['licensee' => 'Licensed to', 'edition' => 'Edition', 'licence_type' => 'Type', 'starts_at' => 'Valid from', 'expires_at' => 'Valid until', 'max_users' => 'User limit', 'notes' => 'Notes'];
            $changes = [];
            foreach ($labels as $field => $label) {
                $old = $licence->{$field};
                $oldText = $old instanceof Carbon ? $old->toDateString() : (string) ($old ?? '');
                $newText = (string) ($new[$field] ?? '');
                if ($oldText !== $newText) {
                    $show = fn (string $v): string => $v === '' ? '-' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::parse($v)->format('d M Y') : \Illuminate\Support\Str::limit($v, 40));
                    $changes[] = $label.': '.$show($oldText).' → '.$show($newText);
                }
            }
            foreach (['support_level' => 'Support level', 'modules' => 'Modules'] as $field => $label) {
                if (($licence->meta[$field] ?? null) !== ($meta[$field] ?? null)) {
                    $changes[] = $label.': '.($licence->meta[$field] ?? '-').' → '.($meta[$field] ?? '-');
                }
            }
            if (! $changes) {
                return $licence;
            }

            $signed = ['licence_number' => $licence->licence_number, 'licensee' => $new['licensee'], 'starts_at' => $new['starts_at'], 'expires_at' => $new['expires_at']];
            $oldKey = (string) $licence->getRawOriginal('key');
            $newKey = $oldKey;
            if (! self::keyMatchesDetails($oldKey, $signed)) {
                $body = substr($oldKey, 0, 96);
                $newKey = $body.self::signature($body, $signed);
                $changes[] = 'Key signature recalculated (the last 32 characters changed)';
            }

            $licence->update([...$new, 'key' => $newKey, 'meta' => $meta]);
            self::event($licence, 'edited', $by, \Illuminate\Support\Str::limit(implode('; ', $changes), 480, '…'));
            self::flush();

            return $licence->refresh();
        });
    }

    /** Removes a licence record. The one running on this system cannot be deleted: deactivate it or replace it first. */
    public static function delete(Licence $licence, User $by): void
    {
        if ($licence->status === Licence::ACTIVE) {
            throw ValidationException::withMessages(['licence' => __('The active licence cannot be deleted. Activate another licence or deactivate this one first.')]);
        }
        $licence->delete();
        self::flush();
    }

    public static function event(Licence $licence, string $event, ?User $by, ?string $note = null): LicenceEvent
    {
        return LicenceEvent::create(['licence_id' => $licence->id, 'event' => $event, 'user_id' => $by?->id, 'note' => $note, 'ip_address' => request()?->ip(), 'created_at' => now()]);
    }

    /* ------------------------------------------------------------------ the licence WIMS is running on */

    public static function tablesExist(): bool
    {
        static $exists = null;

        return $exists ??= \App\Support\SchemaCache::hasTable('licences');
    }

    /** The licence in force: the Active one, otherwise the most recent that was deactivated, otherwise nothing. */
    public static function current(bool $fresh = false): ?Licence
    {
        if (! self::tablesExist()) {
            return null;
        }
        if ($fresh) {
            // The pages people read after an edit: always straight from the database, never from the short cache used by the per-request checks.
            return Licence::query()->whereIn('status', [Licence::ACTIVE, Licence::DEACTIVATED])->orderByRaw("status = 'Active' desc")->orderByDesc('applied_at')->orderByDesc('id')->first();
        }
        if (! self::$currentLoaded) {
            $row = Cache::remember(self::cacheKey(), 60, fn () => Licence::query()->whereIn('status', [Licence::ACTIVE, Licence::DEACTIVATED])->orderByRaw("status = 'Active' desc")->orderByDesc('applied_at')->orderByDesc('id')->first()?->getAttributes());
            self::$currentCache = $row;
            self::$currentLoaded = true;
        }

        return self::$currentCache ? (new Licence)->forceFill(self::$currentCache)->syncOriginal() : null;
    }

    /** Per database, so two WIMS copies sharing one cache folder never see each other's licence. */
    private static function cacheKey(): string
    {
        return 'wims.licence.current.'.DB::connection()->getDatabaseName();
    }

    /**
     * Forget the cached licence. Runs after the surrounding database transaction commits: clearing it earlier lets a request
     * that arrives meanwhile (the page heartbeat, another tab) cache the OLD row again for a minute.
     */
    public static function flush(): void
    {
        self::$currentLoaded = false;
        self::$currentCache = null;
        $key = self::cacheKey();
        DB::afterCommit(fn () => Cache::forget($key));
    }

    /** May people use WIMS now? (When the licence tables are not there yet, yes: nothing to enforce.) */
    public static function usable(): bool
    {
        if (\App\Support\Simulation\Environment::isSimulation()) {
            return true;            // the WIMS licence belongs to production; the simulation has none of its own
        }
        if (! self::tablesExist()) {
            return true;
        }
        $current = self::current();

        return $current !== null && $current->is_valid;
    }

    /* ------------------------------------------------------------------ who may see the WIMS Licence page */

    public const VIEW_KEY = 'licence_view_access';

    private static function licenseeKey(string $licensee): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $licensee)));
    }

    /** "Allow Licence View Icon" per company ("Licensed to"): normalised name => true / false. A company with no entry is allowed. */
    public static function viewAccessMap(): array
    {
        $raw = get_settings(self::VIEW_KEY);
        $map = is_string($raw) ? json_decode($raw, true) : [];

        return is_array($map) ? array_map('boolval', $map) : [];
    }

    public static function viewAllowedFor(?string $licensee): bool
    {
        return (self::viewAccessMap()[self::licenseeKey((string) $licensee)] ?? true) === true;
    }

    public static function setViewAccess(string $licensee, bool $allowed, int $accountId): void
    {
        $map = self::viewAccessMap();
        $map[self::licenseeKey($licensee)] = $allowed;
        \App\Models\Setting::updateOrCreate(['tec_key' => self::VIEW_KEY, 'account_id' => $accountId], ['tec_value' => json_encode($map)]);
    }

    /**
     * May this person see the WIMS Licence icon and page? The manager always can. While there is no usable licence everybody
     * with the permission can, otherwise a company could never open the page to apply its new licence.
     */
    public static function viewAllowed(?User $user): bool
    {
        // Dr Hajas (the Licence Manager) always sees it, whatever is ticked for a company, even while he is acting as another user
        if (! self::tablesExist() || self::isManagerOrActingManager($user) || ! self::usable()) {
            return true;
        }

        return self::viewAllowedFor(self::current()?->licensee);
    }

    /** Short facts for the footer and the browser. */
    public static function summary(): array
    {
        if (\App\Support\Simulation\Environment::isSimulation()) {
            return ['state' => 'active', 'label' => 'Simulation WIMS', 'days_left' => null, 'expires_at' => null];
        }
        if (! self::tablesExist()) {
            return ['state' => 'active', 'label' => 'Licence Active', 'days_left' => null, 'expires_at' => null];
        }
        $l = self::current();
        if (! $l) {
            return ['state' => 'none', 'label' => 'Licence Not Active: No Licence', 'days_left' => null, 'expires_at' => null];
        }
        $state = $l->state;
        $label = match ($state) {
            'active' => 'Licence Active',
            'expiring' => 'Licence Active: '.max(0, $l->days_left).' '.str('day')->plural(max(0, $l->days_left)).' left',
            'expired' => 'Licence Not Active: Expired',
            'deactivated' => 'Licence Not Active: Deactivated',
            'scheduled' => 'Licence Not Active: Starts '.$l->starts_at->format('d M Y'),
            default => 'Licence Not Active',
        };

        return ['state' => $state, 'label' => $label, 'days_left' => $l->days_left, 'expires_at' => $l->expires_at->toDateString()];
    }

    /** The sentence people see when the licence stops them from signing in. */
    public static function blockMessage(): string
    {
        $l = self::current();
        $contact = ' '.__('Please contact :who to renew or reactivate it.', ['who' => self::CONTACT]);
        if (! $l) {
            return __('No active WIMS licence was found for this system.').$contact;
        }

        return match ($l->state) {
            'deactivated' => __('The WIMS licence has been deactivated.').$contact,
            'scheduled' => __('The WIMS licence starts on :date.', ['date' => $l->starts_at->format('d M Y')]).' '.__('Please contact :who.', ['who' => self::CONTACT]),
            default => __('The WIMS licence expired on :date.', ['date' => $l->expires_at->format('d M Y')]).$contact,
        };
    }

    /** First licence created by the migration. */
    public static function seedInitial(string $licensee, string $starts, string $expires, ?int $issuerId): void
    {
        if (Licence::query()->exists()) {
            return;
        }
        $f = ['licence_number' => 'WIMS-LIC-'.Carbon::parse($starts)->format('Y').'-0001', 'licensee' => $licensee, 'starts_at' => $starts, 'expires_at' => $expires];
        $licence = Licence::create([
            ...$f, 'key' => self::makeKey($f), 'edition' => 'Professional', 'licence_type' => 'Subscription', 'status' => Licence::ACTIVE,
            'meta' => ['support_level' => 'Priority', 'modules' => 'All modules'], 'notes' => 'Initial licence, two years from go-live.',
            'issued_by_user_id' => $issuerId, 'issued_at' => now(), 'applied_by_user_id' => $issuerId, 'applied_at' => now(),
        ]);
        self::event($licence, 'issued', $issuerId ? User::find($issuerId) : null, 'Initial licence issued.');
        self::event($licence, 'activated', $issuerId ? User::find($issuerId) : null, 'Licence activated on this system.');
    }
}
