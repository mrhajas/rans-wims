<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Login Logs hold who signed in from where, so opening them asks for the person's own password again even though they are signed in. The confirmation lasts
 * a short while (config security.login_logs_unlock_minutes, renewed while they work in these pages). Without it the person is sent to the confirm page; a wrong
 * password or Cancel there sends them back to the page they came from.
 */
class ConfirmLoginLogsAccess
{
    public const KEY = 'login_logs_unlocked_at';

    public static function unlocked(Request $request): bool
    {
        $at = (int) $request->session()->get(self::KEY, 0);

        return $at > 0 && (time() - $at) <= max(1, (int) config('security.login_logs_unlock_minutes', 10)) * 60;
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            return $next($request);
        }
        if (! self::unlocked($request)) {
            $back = url()->previous();
            $session = $request->session();
            $session->put('login_logs_intended', $request->isMethod('GET') ? $request->fullUrl() : route('login-logs.index'));
            // where "Cancel" and a wrong password lead: the page they came from (never a Login Logs page itself)
            $session->put('login_logs_back', ($back && ! str_contains($back, '/login-logs') && parse_url($back, PHP_URL_HOST) === $request->getHost()) ? $back : route('dashboard'));

            return redirect()->route('login-logs.unlock');
        }
        $request->session()->put(self::KEY, time());           // working in these pages keeps them open

        return $next($request);
    }
}
