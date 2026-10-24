<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Once WIMS is installed the installer pages must never be reachable. The installer package decides that with env(), which answers nothing when the configuration is
 * cached, so this repeats the check through config (app.installed) and refuses /install* itself.
 */
class BlockInstallerWhenInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (config('app.installed') && $request->is('install', 'install/*')) {
            return redirect('/');
        }

        return $next($request);
    }
}
