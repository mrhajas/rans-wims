<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Simulation\Environment;
use App\Support\Simulation\EnvironmentCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The server side of the hidden login-page trigger. It only records a short-lived, encrypted "the next sign-in is for the other WIMS" choice. It grants
 * nothing: the person still has to sign in, against the simulation's own users. Every refusal looks exactly like an address that does not exist.
 */
class EnvironmentChoiceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function choose(Request $request)
    {
        $this->guard($request);

        return response()->json(['ok' => true, 'ttl' => max(1, (int) config('simulation.pending_minutes')) * 60])->withCookie(EnvironmentCookie::pending());
    }

    public function cancel(Request $request)
    {
        abort_unless(Environment::available(), 404);

        return response()->json(['ok' => true])->withCookie(EnvironmentCookie::forget());
    }

    private function guard(Request $request): void
    {
        abort_unless(Environment::available(), 404);
        abort_if(Auth::check(), 404);                                            // switching WIMS needs a full sign-out first
        abort_unless($request->ajax() && $request->header('X-Requested-With') === 'XMLHttpRequest', 404);
        $origin = $request->headers->get('Origin');
        abort_if($origin && parse_url($origin, PHP_URL_HOST) !== $request->getHost(), 404);   // only this site's own page can ask
    }
}
