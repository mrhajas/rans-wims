<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Second line of defence against SQL injection (the first is that every value reaches MySQL as a bound parameter, and every sort column or raw
 * fragment comes from a fixed list).
 *
 * 1. Sign-in pages (login, face login, password reset, two-factor): only plain values are accepted. A user name is letters, digits and . _ @ + -,
 *    an e-mail address the usual characters, a code letters, digits and dashes. Anything else (quotes, semicolons, colons, spaces, arrays, very long
 *    text) is refused with the same message a wrong password gets, before the database is touched.
 * 2. Everywhere else the text a person sends is scanned for the unmistakable shape of an injection (UNION SELECT, "; DROP TABLE", ' OR '1'='1,
 *    SLEEP(5), information_schema ...). Ordinary text is never refused: a semicolon, a colon, an apostrophe or a time such as 08:30 is fine.
 *    Passwords, tokens and signatures are never scanned.
 * Every refusal is written to the log and to the activity log (log name "Security"). Mode: config('security.sqli_guard') = block | log | off.
 */
class SqlInjectionGuard
{
    private const AUTH_PATHS = ['login', 'face-login/challenge', 'face-login/verify', 'forgot-password', 'reset-password', 'two-factor-challenge', 'two-factor-challenge/send'];

    private const SKIP_KEYS = '/(^|[_\-.])(password|passcode|token|signature|descriptor|template|secret|otp|image|photo|file)([_\-.]|$)/i';

    private const PATTERNS = [
        'union select'     => '/\bunion\b[\s\/*()]{0,40}(all\s+|distinct\s+)?select\b/i',
        'stacked query'    => '/;\s*(drop|alter|truncate)\s+(table|database|schema|user)\b|;\s*delete\s+from\b|;\s*insert\s+into\b|;\s*update\s+[\w`.]+\s+set\b|;\s*(shutdown|exec|execute)\b/i',
        'tautology'        => '/[\'"`)]\s*(or|and|\|\||&&)\s+[\'"`(]?[\w.]+[\'"`)]?\s*(=|<>|!=|like|>|<)\s*[\'"`(]?[\w.]+/i',
        'numeric tautology' => '/\b(or|and)\s+(\d+)\s*=\s*\2\b/i',
        'time delay'       => '/\b(sleep|benchmark|pg_sleep)\s*\(\s*[\d(]|waitfor\s+delay\b/i',
        'schema probe'     => '/\binformation_schema\b|\bmysql\.user\b|\bload_file\s*\(|\binto\s+(out|dump)file\b|\bxp_cmdshell\b/i',
        'comment tail'     => '/[\'"`]\s*;?\s*(--[\s\-+]|--$|#\s*$|\/\*)/',
        'column probe'     => '/[\'"`)]\s*(order|group)\s+by\s+\d+/i',
        'subquery'         => '/\(\s*select\s+[\w*@`\'"(]+.*\bfrom\b|\(\s*select\s+(sleep|benchmark|database|version|user)\b/is',
    ];

    public function handle(Request $request, Closure $next)
    {
        $mode = (string) config('security.sqli_guard', 'block');
        if ($mode === 'off') {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        if ($request->isMethod('POST') && in_array($path, self::AUTH_PATHS, true)) {
            $this->guardSignIn($request, $path);
        }

        if ($hit = $this->scan($request->all())) {
            $this->report($request, $hit['field'], $hit['rule'], $hit['value'], $mode);
            if ($mode === 'block') {
                return $this->refuse($request);
            }
        }

        return $next($request);
    }

    /** @return array{field:string,rule:string,value:string}|null */
    private function scan(array $data, string $prefix = '', int $depth = 0): ?array
    {
        if ($depth > 6) {
            return null;
        }
        foreach ($data as $key => $value) {
            $field = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_string($key) && preg_match(self::SKIP_KEYS, $key)) {
                continue;
            }
            if (is_array($value)) {
                if ($hit = $this->scan($value, $field, $depth + 1)) {
                    return $hit;
                }
                continue;
            }
            if (! is_string($value) || $value === '' || strlen($value) > 20000) {
                continue;
            }
            // percent-encoded or doubled input is looked at too (a browser has already decoded one level)
            foreach (array_unique([$value, rawurldecode($value)]) as $v) {
                foreach (self::PATTERNS as $rule => $rx) {
                    if (preg_match($rx, $v)) {
                        return ['field' => $field, 'rule' => $rule, 'value' => $v];
                    }
                }
            }
        }

        return null;
    }

    /** Sign-in pages take plain strings only. */
    private function guardSignIn(Request $request, string $path): void
    {
        $rules = [
            'username' => '/^[A-Za-z0-9._@+\-]{1,100}$/', 'email' => '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/',
            'code' => '/^[A-Za-z0-9\- ]{1,64}$/', 'recovery_code' => '/^[A-Za-z0-9\- ]{1,64}$/', 'challenge_answer' => '/^[0-9\-]{1,6}$/',
        ];
        foreach ($request->all() as $key => $value) {
            $bad = false;
            $rule = null;
            if (is_array($value)) {
                if (in_array($key, ['username', 'email', 'password', 'code', 'recovery_code', 'token', 'challenge_answer', 'password_confirmation'], true)) {
                    $bad = true;
                    $rule = 'not plain text';
                }
            } elseif (isset($rules[$key]) && is_string($value) && $value !== '' && (strlen($value) > 190 || ! preg_match($rules[$key], $value))) {
                $bad = true;
                $rule = 'characters not allowed in ' . $key;
            } elseif (is_string($value) && in_array($key, ['password', 'password_confirmation'], true) && strlen($value) > 1024) {
                $bad = true;
                $rule = 'too long';
            } elseif (is_string($value) && strlen($value) > 5000 && ! preg_match(self::SKIP_KEYS, (string) $key)) {
                $bad = true;
                $rule = 'too long';
            }
            if ($bad) {
                $this->report($request, (string) $key, 'sign-in: ' . $rule, is_string($value) ? $value : json_encode($value), (string) config('security.sqli_guard', 'block'));
                if (config('security.sqli_guard', 'block') === 'block') {
                    // the same words a wrong password gets: it never says what was wrong with the text
                    $field = in_array($key, ['email'], true) || $path === 'forgot-password' || $path === 'reset-password' ? 'email' : (in_array($key, ['code', 'recovery_code'], true) ? 'code' : 'username');
                    throw ValidationException::withMessages([$field => [$field === 'email' ? __('We could not process that request.') : __('These credentials do not match our records.')]]);
                }
            }
        }
    }

    private function refuse(Request $request)
    {
        $message = __('That input was refused for security reasons. Please remove special characters and try again.');
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            if ($request->isMethod('GET')) {
                abort(422, $message);
            }
        }
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            abort(422, $message);
        }

        return back()->with('error', $message)->withErrors(['security' => $message]);
    }

    private function report(Request $request, string $field, string $rule, string $value, string $mode): void
    {
        $safe = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), 0, 160);
        \App\Services\SecurityAlert::note((string) $request->ip(), 'refused input: ' . $rule);
        try {
            Log::channel(config('logging.default'))->warning('Security: suspicious input ' . ($mode === 'block' ? 'refused' : 'noted'), ['rule' => $rule, 'field' => $field, 'value' => $safe, 'path' => $request->path(), 'method' => $request->method(), 'ip' => $request->ip(), 'user_id' => optional($request->user())->id]);
            if (function_exists('log_activity')) {
                log_activity('Suspicious input ' . ($mode === 'block' ? 'refused' : 'noted'), ['rule' => $rule, 'field' => $field, 'value' => $safe, 'path' => $request->path(), 'method' => $request->method(), 'ip' => $request->ip()], null, 'Security');
            }
        } catch (\Throwable) {
            // a log problem must never turn into an error page
        }
    }
}
