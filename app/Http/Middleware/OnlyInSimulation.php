<?php

namespace App\Http\Middleware;

use App\Support\Simulation\Environment;
use Closure;
use Illuminate\Http\Request;

/** The simulation workspace pages exist only inside Simulation WIMS; in production they answer like an unknown address. */
class OnlyInSimulation
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Environment::isSimulation(), 404);

        return $next($request);
    }
}
