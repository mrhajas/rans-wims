<?php

namespace App\Providers;

use App\Support\Simulation\Environment;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Carries the environment beyond the web request:
 *  - every queued job remembers the environment it was created in, and a worker moves to that environment BEFORE the job touches any data;
 *  - a command run with WIMS_ENVIRONMENT=simulation (for example the migrations of the simulation) works on the simulation databases only;
 *  - what a process remembers (settings ...) is forgotten when the environment changes.
 */
class SimulationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The environment is decided FIRST, before any other provider boots, so the whole application (its database connections, session, cache, files) is built
        // for the right environment from the start and nothing holds a connection, a session store or a guard from the other one.
        if (! $this->app->runningInConsole() && isset($_SERVER['REQUEST_URI'])) {
            $d = Environment::decide(\App\Support\Simulation\EnvironmentCookie::fromGlobals(), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), isset($_SERVER['HTTP_X_INERTIA']));
            if ($d['env'] === Environment::SIMULATION) {
                try {
                    Environment::activate(Environment::SIMULATION);
                } catch (\Throwable) {
                    // ResolveEnvironment answers the request with a plain 503 and never falls back
                }
            }
        }

        // the PDF maker puts the simulation marking on every page when in the simulation
        $this->app->bind('dompdf.wrapper', fn ($app) => new \App\Support\Simulation\SimulationPdf($app['dompdf'], $app['config'], $app['files'], $app['view']));
    }

    public function boot(): void
    {
        Environment::forgetOnSwitch(fn () => \App\Services\Subcontracts\SubcontractSettings::forget());
        Environment::forgetOnSwitch(fn () => \App\Services\Accounts\AcctSettings::forget());
        Environment::forgetOnSwitch(fn () => \App\Services\Qr\QrSettings::forget());
        Environment::forgetOnSwitch(fn () => \App\Services\FeatureManager::flushCache());

        Queue::createPayloadUsing(fn () => ['wims_env' => Environment::current()]);
        Event::listen(JobProcessing::class, function (JobProcessing $e) {
            $env = Environment::normalise($e->job->payload()['wims_env'] ?? null);
            Environment::activate($env);                 // a job with no marker is a production job; it never inherits whatever the worker was doing
            if ($env === Environment::SIMULATION) {
                Environment::assertConnected();
            }
        });

        if ($this->app->runningInConsole() && getenv('WIMS_ENVIRONMENT') === Environment::SIMULATION) {
            Environment::activate(Environment::SIMULATION);
        }
    }
}
