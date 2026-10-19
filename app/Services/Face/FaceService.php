<?php

namespace App\Services\Face;

use App\Models\FaceDevice;
use App\Models\FaceTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/**
 * Face login. The browser turns a camera frame into a 128-number "face template"; the server only ever stores and compares those
 * numbers (encrypted), never pictures. A user signs in by typing their username first, so a face is only compared against that
 * one person's templates (1 to 1), never searched across everyone.
 */
class FaceService
{
    public const DIM = 128;
    public const ENROL_SAMPLES = 5;
    public const LOGIN_FRAMES = 3;
    /** Frames taken moments apart of a live face differ a little; identical frames mean a replayed or still image. */
    public const MIN_FRAME_SPREAD = 0.02;
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;
    public const NONCE_TTL = 120;
    /** How far the nose must move (see faceEngine.js TURN) for a head turn to count, and the most that is believable. */
    public const TURN_MIN = 0.12;
    public const TURN_MAX = 0.36;

    /** @return list<float>|null the vector cleaned up, or null when it is not a valid 128-number template */
    public static function clean(mixed $v): ?array
    {
        if (! is_array($v) || count($v) !== self::DIM) {
            return null;
        }
        $out = [];
        foreach ($v as $n) {
            if (! is_numeric($n) || ! is_finite((float) $n) || abs((float) $n) > 5) {
                return null;
            }
            $out[] = round((float) $n, 6);
        }

        return array_values($out);
    }

    /** Euclidean distance: smaller = more alike (config/face.php). */
    public static function threshold(): float
    {
        return (float) config('face.threshold', 0.45);
    }

    public static function distance(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0; $i < self::DIM; $i++) {
            $d = $a[$i] - $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /** Smallest distance between one probe and any enrolled template. */
    public static function best(array $probe, array $templates): float
    {
        $best = INF;
        foreach ($templates as $t) {
            if (is_array($t) && count($t) === self::DIM) {
                $best = min($best, self::distance($probe, $t));
            }
        }

        return $best;
    }

    /** Do all enrolment samples look like the same person? (stops somebody enrolling a mix of faces) */
    public static function consistent(array $samples): bool
    {
        foreach ($samples as $i => $a) {
            foreach ($samples as $j => $b) {
                if ($j > $i && self::distance($a, $b) > self::threshold() + 0.1) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Frames of a live face are alike but never exactly equal, and never as different as two people. */
    public static function looksLive(array $frames): bool
    {
        $max = 0.0;
        foreach ($frames as $i => $a) {
            foreach ($frames as $j => $b) {
                if ($j > $i) {
                    $max = max($max, self::distance($a, $b));
                }
            }
        }

        return $max >= (float) config('face.min_spread', self::MIN_FRAME_SPREAD) && $max <= (float) config('face.max_spread', 0.45);
    }

    /** The average of the per-frame distances must also be comfortably inside the threshold (one lucky frame is not enough). */
    public static function meanOk(array $distances): bool
    {
        return count($distances) > 0 && (array_sum($distances) / count($distances)) <= self::threshold() - (float) config('face.mean_margin', 0.04);
    }

    /** A frame practically identical to an enrolled sample is a replayed template, not a camera frame. */
    public static function tooPerfect(array $frames, array $templates): bool
    {
        foreach ($frames as $f) {
            if (self::best($f, $templates) < (float) config('face.too_perfect', 0.03)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Look-alike guard: is somebody ELSE's enrolled face as close to (or closer than) this one? Then the scan is not specific enough to
     * open this account, whoever is in front of the camera.
     */
    public static function lookAlikeConflict(User $user, array $frames, float $own): bool
    {
        $margin = (float) config('face.cohort_margin', 0.05);
        $others = FaceTemplate::query()->where('user_id', '!=', $user->id)->whereHas('user', fn ($q) => $q->where('is_active', true))->get();
        foreach ($others as $t) {
            $v = $t->vectors();
            if (! $v) {
                continue;
            }
            foreach ($frames as $f) {
                if (self::best($f, $v) < $own + $margin) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Is this face (most of the samples) already enrolled on a different, active account? Face ID needs a face that belongs to one account. */
    public static function enrolledElsewhere(User $user, array $samples): bool
    {
        $others = FaceTemplate::query()->where('user_id', '!=', $user->id)->whereHas('user', fn ($q) => $q->where('is_active', true))->get();
        foreach ($others as $t) {
            $v = $t->vectors();
            $close = $v ? count(array_filter($samples, fn ($s) => self::best($s, $v) <= self::threshold())) : 0;
            if ($close >= 2) {
                return true;
            }
        }

        return false;
    }

    public static function templateFor(User $user): ?FaceTemplate
    {
        return FaceTemplate::query()->where('user_id', $user->id)->first();
    }

    public static function enrolled(User $user): bool
    {
        return self::templateFor($user) !== null;
    }

    /** What the profile page shows. */
    public static function statusFor(User $user): array
    {
        $t = self::templateFor($user);

        return [
            'enrolled' => (bool) $t,
            'enrolled_at' => $t?->enrolled_at?->toIso8601String(),
            'samples' => $t?->sample_count ?? 0,
            'last_used_at' => $t?->last_used_at?->toIso8601String(),
            'locked' => (bool) ($t && $t->locked_until && $t->locked_until->isFuture()),
            'samples_needed' => self::ENROL_SAMPLES,
            'devices' => FaceDevice::query()->where('user_id', $user->id)->active()->orderBy('id')->get(['id', 'device_id', 'label', 'last_used_at', 'created_at'])
                ->map(fn ($d) => ['id' => $d->id, 'device_id' => $d->device_id, 'label' => $d->label, 'last_used_at' => $d->last_used_at?->toIso8601String(), 'added_at' => $d->created_at?->toIso8601String()])->all(),
        ];
    }

    // ---- the sign-in challenge ------------------------------------------------------------------------------------------------

    /**
     * What the person must do on camera: blink when the ring flashes (twice, or three times plus one head turn when the sign-in looks
     * unusual). The waits before each flash are random and chosen here, so a recorded video of the person cannot have the right blinks
     * at the right moments.
     *
     * @return list<array{k:string,delay?:int}>
     */
    public static function plan(bool $elevated): array
    {
        $cues = $elevated ? (int) config('face.cues_risky', 3) : (int) config('face.cues_normal', 2);
        $plan = [];
        for ($i = 0; $i < $cues; $i++) {
            $plan[] = ['k' => 'cue', 'delay' => random_int((int) config('face.cue_delay_min', 1100), (int) config('face.cue_delay_max', 2700))];
        }
        if ($elevated) {
            array_splice($plan, random_int(1, count($plan)), 0, [['k' => random_int(0, 1) ? 'left' : 'right']]);
        }

        return $plan;
    }

    /** Is this sign-in unusual enough to ask for more? */
    public static function elevated(User $user, FaceDevice $device, ?FaceTemplate $template, string $ip): bool
    {
        if ($template && ($template->failed_attempts > 0 || ($template->last_failed_at && $template->last_failed_at->gt(now()->subDay())))) {
            return true;
        }
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subDays((int) config('face.stale_device_days', 30)))) {
            return true;
        }
        $net = fn (?string $a) => $a && str_contains($a, '.') ? implode('.', array_slice(explode('.', $a), 0, 3)) : ($a ? substr($a, 0, 19) : null);

        return $device->last_used_ip && $net($device->last_used_ip) !== $net($ip);
    }

    /** A signed, one-use, short-lived token binding the challenge (steps, delays, device, browser) to this login attempt. */
    public static function makeNonce(string $username, string $deviceId, array $plan, string $agent): string
    {
        return Crypt::encryptString(json_encode([
            'u' => mb_strtolower($username), 'd' => $deviceId, 'p' => $plan, 'a' => substr(hash('sha256', $agent), 0, 16),
            'i' => now()->timestamp, 'x' => now()->timestamp + (int) config('face.nonce_ttl', self::NONCE_TTL), 'r' => bin2hex(random_bytes(12)),
        ]));
    }

    /** @return array{u:string,d:string,p:list<array>,a:string,i:int,x:int,r:string}|null */
    public static function readNonce(string $nonce): ?array
    {
        try {
            $p = json_decode(Crypt::decryptString($nonce), true, 6, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($p) && isset($p['u'], $p['d'], $p['p'], $p['a'], $p['i'], $p['x'], $p['r']) && $p['x'] >= now()->timestamp ? $p : null;
    }

    public static function sameBrowser(array $nonce, string $agent): bool
    {
        return hash_equals($nonce['a'], substr(hash('sha256', $agent), 0, 16));
    }

    /**
     * Checks what the browser says it saw against what the server asked for. A recorded or scripted answer has to get every wait, every
     * blink delay and the total time right. Returns an error code, or null when it all fits.
     *
     * @param  array  $t  {v, camera, span, steps:[{k, delay?, latency?, dur?, misses?, peak?}]}
     */
    public static function telemetryError(array $t, array $plan, int $issuedAt): ?string
    {
        $steps = $t['steps'] ?? null;
        if (! is_array($steps) || count($steps) !== count($plan) + 1 || ($steps[0]['k'] ?? null) !== 'front') {
            return 'steps';
        }
        $camera = (string) ($t['camera'] ?? '');
        if ($camera !== '' && preg_match('/('.config('face.virtual_cameras').')/i', $camera)) {
            return 'virtual_camera';
        }

        $minElapsed = 0;
        foreach ($plan as $i => $want) {
            $got = $steps[$i + 1] ?? [];
            if (($got['k'] ?? null) !== $want['k']) {
                return 'steps';
            }
            if ($want['k'] === 'cue') {
                $latency = $got['latency'] ?? null;
                $dur = $got['dur'] ?? null;
                if ((int) ($got['delay'] ?? -1) !== (int) $want['delay'] || ! is_numeric($latency) || ! is_numeric($dur) || (int) ($got['misses'] ?? 99) > 3) {
                    return 'cue';
                }
                // a person needs a moment to react (and a script that answers instantly gives itself away)
                if ($latency < config('face.blink_latency_min', 110) || $latency > config('face.blink_latency_max', 1100)) {
                    return 'latency';
                }
                if ($dur < config('face.blink_duration_min', 40) || $dur > config('face.blink_duration_max', 800)) {
                    return 'blink';
                }
                $minElapsed += (int) $want['delay'] + (int) $latency;
            } else {
                $peak = $got['peak'] ?? null;
                if (! is_numeric($peak) || $peak < self::TURN_MIN || $peak > self::TURN_MAX) {
                    return 'turn';
                }
                $minElapsed += 900;
            }
        }
        // the whole thing cannot have been finished faster than its own waits allow, and cannot take longer than the token lives
        $elapsedMs = (now()->timestamp - $issuedAt) * 1000;
        if ($elapsedMs + 1500 < $minElapsed + 800) {
            return 'too_fast';
        }
        $span = (int) ($t['span'] ?? 0);
        if ($span < $minElapsed || $span > $elapsedMs + 3000) {
            return 'span';
        }

        return null;
    }

    // ---- device key ----------------------------------------------------------------------------------------------------------------

    /** The device's public key as sent by the browser (base64 SPKI of an ECDSA P-256 key), or null when it is not one. */
    public static function cleanPublicKey(mixed $b64): ?string
    {
        if (! is_string($b64) || strlen($b64) > 400 || ! preg_match('#^[A-Za-z0-9+/]+=*$#', $b64)) {
            return null;
        }
        $key = @openssl_pkey_get_public(self::pem($b64));
        if (! $key) {
            return null;
        }
        $d = openssl_pkey_get_details($key);

        return ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? null) === 'prime256v1' ? $b64 : null;
    }

    private static function pem(string $b64): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($b64, 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** Was this message signed by the device? (the browser signs with ECDSA P-256 / SHA-256; its raw r||s signature is turned into DER here) */
    public static function signatureValid(FaceDevice $device, string $message, string $signatureB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        if ($sig === false || $sig === '') {
            return false;
        }
        if (strlen($sig) === 64) {
            $sig = self::rawToDer($sig);
        }
        $key = @openssl_pkey_get_public(self::pem((string) $device->public_key));

        return $key && openssl_verify($message, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function rawToDer(string $raw): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) > 0x7F) {
                $b = "\x00".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    /** The lock-out grows each time it is triggered: 15 minutes, 30, 60 ... up to a day. */
    public static function lockMinutes(int $strikes): int
    {
        return (int) min((int) config('face.lock_max_minutes', 1440), (int) config('face.lock_minutes', self::LOCK_MINUTES) * (2 ** max(0, $strikes)));
    }

    /** A readable name for a browser, used to tell a person's devices apart. */
    public static function deviceLabel(string $agent): string
    {
        $a = user_agent_info($agent);

        return trim(($a['browser'] ?? 'Browser').' on '.($a['os'] ?? 'unknown system'));
    }

}
