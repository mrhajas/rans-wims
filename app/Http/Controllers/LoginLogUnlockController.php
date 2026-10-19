<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ConfirmLoginLogsAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

/** The password check in front of Login Logs. Needs the right to read them; the check itself is the person's own password. */
class LoginLogUnlockController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user() && $request->user()->can('read-login-logs'), 403);
        abort_if(\App\Support\Impersonation::isActive(), 403, __('Confirm from your own login, not while logged in as another user.'));
    }

    public function show(Request $request)
    {
        $this->guard($request);
        if (ConfirmLoginLogsAccess::unlocked($request)) {
            return redirect()->route('login-logs.index');
        }

        return Inertia::render('LoginLog/Unlock', ['back' => $request->session()->get('login_logs_back', route('dashboard'))]);
    }

    public function check(Request $request)
    {
        $this->guard($request);
        $back = $request->session()->get('login_logs_back', route('dashboard'));
        $request->validate(['password' => ['required', 'string', 'max:1024']]);
        $key = 'login-logs-unlock:' . $request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect($back)->with('error', __('Too many wrong passwords. Try again in :m minute(s).', ['m' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));
        }
        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            RateLimiter::hit($key, 600);
            log_activity('Login Logs password check refused: wrong password', [], null, 'LoginSecurity');
            \App\Services\SecurityAlert::note((string) $request->ip(), 'wrong password for the Login Logs');

            return redirect($back)->with('error', __('The password was not correct, so Login Logs were not opened.'));
        }
        RateLimiter::clear($key);
        $request->session()->put(ConfirmLoginLogsAccess::KEY, time());
        log_activity('Login Logs opened after password confirmation', [], null, 'LoginSecurity');

        return redirect($request->session()->pull('login_logs_intended', route('login-logs.index')));
    }
}
