<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\SecurityHeaders::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    /**
     * The order middleware runs in when a route has several. The SQL injection guard sits right after the session starts (so its message can be
     * shown) and before everything that reads the input, such as the sign-in rate limiter that reads the user name.
     *
     * @var array
     */
    protected $middlewarePriority = [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \App\Http\Middleware\ResolveEnvironment::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\EnsureEnvironmentIntegrity::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\SqlInjectionGuard::class,
        \App\Http\Middleware\ThrottleRequestFloods::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
        \Laravel\Jetstream\Http\Middleware\ShareInertiaData::class,
        \App\Http\Middleware\HandleInertiaRequests::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \App\Http\Middleware\ResolveEnvironment::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\EnsureEnvironmentIntegrity::class,
            \App\Http\Middleware\MarkSimulationExports::class,
            \App\Http\Middleware\RaiseLimitsForDocuments::class,
            \Laravel\Jetstream\Http\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \App\Http\Middleware\SqlInjectionGuard::class,
            \App\Http\Middleware\EnforceAddressRules::class,
            \App\Http\Middleware\ThrottleRequestFloods::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ImpersonationGuard::class,
            \App\Http\Middleware\EnforceIdleTimeout::class,
            \App\Http\Middleware\EnforceDeviceLimit::class,
            // Maintenance Lock for EVERY web request (not just the main route group), so no authenticated
            // route — Jetstream's /user/* pages included — can be reached by a non-allowed role while it is on.
            \App\Http\Middleware\SystemLockdown::class,
            \App\Http\Middleware\EnforceLicence::class,
            \App\Http\Middleware\EnforcePrayerLock::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\LogDownloadPrintActions::class,
            \Tecdiary\Installer\Http\Middleware\RedirectIfNotInstalled::class,
            \App\Http\Middleware\Language::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth'             => \App\Http\Middleware\Authenticate::class,
        'auth.basic'       => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'cache.headers'    => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can'              => \Illuminate\Auth\Middleware\Authorize::class,
        'guest'            => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'login-logs.unlocked' => \App\Http\Middleware\ConfirmLoginLogsAccess::class,
        'signed'           => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle'         => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified'         => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'remember'         => \Reinink\RememberQueryStrings::class,
        'install'          => \Tecdiary\Installer\Http\Middleware\CanInstall::class,
        'purchased'        => \App\Http\Middleware\Purchased::class,
        'log.download.print' => \App\Http\Middleware\LogDownloadPrintActions::class,
        'system.lockdown'  => \App\Http\Middleware\SystemLockdown::class,
        'licence.manager'  => \App\Http\Middleware\LicenceManagerOnly::class,
        'feature.gate'     => \App\Http\Middleware\FeatureGate::class,
        'reject.offline.mutations' => \App\Http\Middleware\RejectOfflineMutations::class,
        'not.simulation'   => \App\Http\Middleware\NotInSimulation::class,
        'only.simulation'  => \App\Http\Middleware\OnlyInSimulation::class,
    ];
}
