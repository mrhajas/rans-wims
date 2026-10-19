<?php

namespace App\Http\Middleware;

use App\Support\Simulation\Environment;
use Closure;
use Illuminate\Http\Request;

/** For things that only make sense in production (Face ID sign-in, real gateways, backups ...): in Simulation WIMS they answer like an unknown address. */
class NotInSimulation
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(Environment::isSimulation(), 404);

        return $next($request);
    }
}
