<?php

namespace Tecdiary\Installer\Http\Middleware;

use Closure;

class RedirectIfNotInstalled
{
    public function handle($request, Closure $next)
    {
        if (! config('app.installed', false)) {
            return redirect('/install');
        }

        return $next($request);
    }
}
