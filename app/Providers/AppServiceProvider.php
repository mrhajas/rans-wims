<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Tecdiary\Laravel\Attachments\AttachmentsServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        app()->useLangPath(base_path('lang'));

        // a logout frees the device slot at once
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function () {
            try {
                \App\Services\Security\DeviceLimit::release((string) session()->getId());
            } catch (\Throwable $e) { /* never block a logout */ }
        });

        // A live server never runs debug tools, whatever the .env says by mistake: they print passwords and database details and the debug bar can run SQL
        if (app()->environment('production')) {
            config(['app.debug' => false, 'debugbar.enabled' => false, 'ignition.enable_runnable_solutions' => false]);
        }


        // Delivery Logs: every email WIMS sends is written to the log of everything that goes out
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, fn ($e) => \App\Services\WimsConnect\OutboundLog::mailSent($e));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, fn ($e) => \App\Services\WimsConnect\OutboundLog::mailSending($e));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationFailed::class, fn ($e) => \App\Services\WimsConnect\OutboundLog::notificationFailed($e));

        // Photo / upload links must point at the address the browser actually used (localhost, the office LAN IP, the live
        // domain) — not whatever APP_URL says, or every staff / item / asset photo breaks for anyone using another address.
        if (! $this->app->runningInConsole() && ($request = request()) && $request->getHost()) {
            $origin = rtrim($request->getSchemeAndHttpHost(), '/');
            // Simulation WIMS keeps its own files in a sub-folder of each disk: the links point there too
            $sim = \App\Support\Simulation\Environment::isSimulation() ? '/' . config('simulation.storage_folder') : '';
            config([
                'filesystems.disks.assets.url' => $origin.'/uploads'.$sim,
                'filesystems.disks.images.url' => $origin.'/images'.$sim, // user profile photos
                'filesystems.disks.logos.url' => $origin.'/logos'.$sim,
                'filesystems.disks.public.url' => $origin.'/storage'.$sim,
            ]);
        }
        JsonResource::withoutWrapping();

        // An asset belongs to ONE module: the Asset Register (/company-assets) or Transport Vehicles (/transport-vehicles).
        // Opening it through the other module's address sends you to the right place instead of mixing the two
        // (links and bookmarks keep working; changes made through the wrong module are refused).
        \Illuminate\Support\Facades\Route::bind('asset', function ($value, $route) {
            $asset = (new \App\Models\Asset())->resolveRouteBinding($value);
            abort_unless($asset, 404);

            $uri = (string) $route->uri();
            $isTransport = $asset->registered_in === \App\Models\Asset::REGISTERED_IN_TRANSPORT;
            $wrong = (str_starts_with($uri, 'company-assets') && $isTransport) || (str_starts_with($uri, 'transport-vehicles') && ! $isTransport);

            if ($wrong) {
                $request = request();
                $name = (string) optional($request->route())->getName();

                if ($request->isMethod('GET') && preg_match('/\.(show|edit)$/', $name)) {
                    $kind = str_ends_with($name, '.edit') ? 'edit' : 'show';

                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        redirect()->route(($isTransport ? 'transport-vehicles.' : 'assets.') . $kind, $asset->id)
                    );
                }
                abort(404);
            }

            return $asset;
        });
    }

    public function register()
    {
        $this->app->extend(
            \Illuminate\Translation\Translator::class,
            fn ($translator) => new \App\Helpers\Translator($translator->getLoader(), $translator->getLocale())
        );

        AttachmentsServiceProvider::ignoreMigrations();
        $this->app->extend(\Illuminate\Pagination\LengthAwarePaginator::class, fn ($paginator) => $paginator->onEachSide(2));
    }
}
