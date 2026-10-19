<?php

namespace App\Support\Simulation;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The cookie that carries the environment. It is ENCRYPTED by the application (EncryptCookies), so a browser can neither read nor forge it, and it
 * only ever says which database set to LOOK in: whoever holds it still has to sign in, and EnsureEnvironmentIntegrity checks it against the session.
 * pending = chosen on the login page, not signed in yet (short lived); otherwise it belongs to a signed-in simulation session (until the browser closes).
 */
final class EnvironmentCookie
{
    public static function name(): string
    {
        return (string) config('simulation.cookie');
    }

    /** @return array{e:string,p:bool,x:int}|null */
    public static function read(Request $request): ?array
    {
        $raw = $request->cookies->get(self::name());
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        if (! is_array($d) || ($d['e'] ?? null) !== Environment::SIMULATION) {
            return null;
        }

        return ['e' => Environment::SIMULATION, 'p' => ! empty($d['p']), 'x' => (int) ($d['x'] ?? 0)];
    }

    /**
     * The same cookie read at the very start of a request, before any middleware: decrypted with the application key exactly as the framework does.
     * Anything that does not decrypt, or was not written for this cookie's name, is simply "no cookie".
     */
    public static function fromGlobals(): ?array
    {
        $raw = $_COOKIE[self::name()] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $enc = app('encrypter');
            $plain = $enc->decrypt($raw, false);
            $keys = array_merge([$enc->getKey()], $enc->getPreviousKeys());
            $value = \Illuminate\Cookie\CookieValuePrefix::validate(self::name(), $plain, $keys);
            if ($value === null) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        $d = json_decode((string) $value, true);
        if (! is_array($d) || ($d['e'] ?? null) !== Environment::SIMULATION) {
            return null;
        }

        return ['e' => Environment::SIMULATION, 'p' => ! empty($d['p']), 'x' => (int) ($d['x'] ?? 0)];
    }

    public static function pending(): Cookie
    {
        $minutes = max(1, (int) config('simulation.pending_minutes'));

        return self::make(['e' => Environment::SIMULATION, 'p' => 1, 'x' => time() + $minutes * 60], $minutes);
    }

    public static function signedIn(): Cookie
    {
        return self::make(['e' => Environment::SIMULATION, 'p' => 0, 'x' => 0], 0);
    }

    public static function forget(): Cookie
    {
        return new Cookie(self::name(), '', 1, '/', config('session.domain'), (bool) config('session.secure'), true, false, 'lax');
    }

    private static function make(array $payload, int $minutes): Cookie
    {
        return new Cookie(self::name(), json_encode($payload), $minutes > 0 ? time() + $minutes * 60 : 0, '/', config('session.domain'), (bool) config('session.secure'), true, false, 'lax');
    }
}
