<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenceService;
use Closure;
use Illuminate\Http\Request;

/** The Licence Manager belongs to Dr Hajas alone. Everybody else gets a plain 404, as if the pages did not exist. */
class LicenceManagerOnly
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(LicenceService::isManager($request->user()), 404);

        return $next($request);
    }
}
