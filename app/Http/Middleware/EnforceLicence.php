<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * WIMS Licence. While the licence is expired, deactivated or missing, nobody can use WIMS except the Licence Manager
 * (Dr Hajas), who must still be able to sign in to issue or reactivate a licence. People who are already signed in are
 * signed out on their next request and see why on the login page. The password step (FortifyServiceProvider) blocks new sign-ins.
 */
class EnforceLicence
{
    public function handle(Request $request, Closure $next)
    {
        if (LicenceService::usable()) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || LicenceService::isManagerOrActingManager($user)) {
            return $next($request);
        }

        $notice = LicenceService::blockMessage();

        if (! $request->hasSession()) {
            abort(403, $notice);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $request->session()->flash('error', $notice);
        $request->session()->flash('licence_notice', $notice);

        $loginUrl = route('login', ['licence' => 1]);

        return $request->header('X-Inertia') ? Inertia::location($loginUrl) : redirect()->to($loginUrl);
    }
}
