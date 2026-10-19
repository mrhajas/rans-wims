<?php

namespace App\Listeners;

use App\Support\Simulation\Environment;
use App\Support\Simulation\EnvironmentCookie;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;

/** A session is marked with the WIMS it signed in to; leaving WIMS (logging out) also forgets the choice. */
class RecordSessionEnvironment
{
    public function handleLogin(Login $event): void
    {
        if (! app()->bound('session.store') || ! request()->hasSession()) {
            return;
        }
        request()->session()->put('wims.env', Environment::current());
        if (Environment::isSimulation()) {
            Cookie::queue(EnvironmentCookie::signedIn());
            \App\Services\Simulation\SimulationLoginAlert::signedIn($event->user, request());
        }
    }

    /** a refused sign-in attempt in the simulation: counted, and the Super Admins are told when one address keeps failing */
    public function handleFailed(Failed $event): void
    {
        if (Environment::isSimulation()) {
            \App\Services\Simulation\SimulationLoginAlert::refused((string) ($event->credentials['username'] ?? ''), request());
        }
    }

    public function handleLogout(Logout $event): void
    {
        Cookie::queue(EnvironmentCookie::forget());
    }
}
