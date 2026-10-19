<?php

namespace App\Support\Simulation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Which WIMS a request belongs to: PRODUCTION (the default, always) or SIMULATION.
 *
 * One application, two sets of databases. The module code never changes database by itself: this class points the two connections every module already
 * uses ('mysql' and 'accounts') at the production or the simulation database ONCE, at the very start of a request (before the session starts), and gives
 * the simulation its own session cookie, cache, file folders and permission cache. Mail is not sent and no outside request leaves from the simulation.
 *
 * The decision never comes from the browser: ResolveEnvironment reads an ENCRYPTED cookie that only this server issues, and EnsureEnvironmentIntegrity
 * compares it with the session marker on every request.
 */
final class Environment
{
    public const PRODUCTION = 'production';

    public const SIMULATION = 'simulation';

    private static string $current = self::PRODUCTION;

    /** things that remember a value for the length of a process (settings, feature flags ...) and must forget it when the environment changes */
    private static array $memos = [];

    /** the production settings that were replaced, so a process (a test, a queue worker) can go back */
    private static ?array $saved = null;

    /** A class that keeps a value in memory registers how to forget it here. */
    public static function forgetOnSwitch(callable $forget): void
    {
        self::$memos[] = $forget;
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function isSimulation(): bool
    {
        return self::$current === self::SIMULATION;
    }

    /** Is the simulation switched on and fully configured on this server? (When it is not, nothing about it answers: its address behaves like any unknown address.) */
    public static function available(): bool
    {
        return (bool) config('simulation.enabled')
            && (string) config('database.connections.mysql_simulation.database') !== ''
            && (string) config('database.connections.accounts_simulation.database') !== ''
            && (string) config('database.connections.mysql_simulation.username') !== '';
    }

    /**
     * Which environment a request belongs to, from the server's own cookie (already decrypted) and what the request is. Production unless everything says simulation.
     *
     * @param array{e:string,p:bool,x:int}|null $cookie
     * @return array{env:string,clear:bool} clear = the cookie is no longer any good and must be removed
     */
    public static function decide(?array $cookie, string $method, string $path, bool $inertia): array
    {
        if (! $cookie) {
            return ['env' => self::PRODUCTION, 'clear' => false];
        }
        if (! self::available()) {
            return ['env' => self::PRODUCTION, 'clear' => true];
        }
        // The login page always opens on the real WIMS unless the hidden choice is made on it again: opening or refreshing it (a document request) forgets the choice
        // AND any leftover of an earlier simulation sign-in (a session that ended without a clean sign-out). The page's own script moving around keeps a fresh choice.
        if (strtoupper($method) === 'GET' && trim($path, '/') === 'login' && ! $inertia) {
            return ['env' => self::PRODUCTION, 'clear' => true];
        }
        // the choice made on the login page expires
        if ($cookie['p'] && $cookie['x'] < time()) {
            return ['env' => self::PRODUCTION, 'clear' => true];
        }

        return ['env' => self::SIMULATION, 'clear' => false];
    }

    /** Only these two values exist; anything else is treated as production. */
    public static function normalise(?string $value): string
    {
        return $value === self::SIMULATION ? self::SIMULATION : self::PRODUCTION;
    }

    public static function activate(string $env): void
    {
        $env = self::normalise($env);
        if ($env === self::$current) {
            return;
        }
        $env === self::SIMULATION ? self::toSimulation() : self::toProduction();
    }

    private static function toSimulation(): void
    {
        if (! self::available()) {
            throw new EnvironmentUnavailable('The simulation environment is not available.');
        }
        self::$saved ??= [
            'mysql' => config('database.connections.mysql'), 'accounts' => config('database.connections.accounts'), 'session.cookie' => config('session.cookie'),
            'cache.path' => config('cache.stores.file.path'), 'permission.key' => config('permission.cache.key'), 'mail.default' => config('mail.default'),
            'disks' => collect(config('filesystems.disks'))->map(fn ($d) => array_intersect_key($d, array_flip(['root', 'url'])))->all(), 'queue' => config('queue.default'),
        ];
        // the simulation's own databases, with the simulation's own login: nothing of production's credentials is used from here on
        config([
            'database.connections.mysql' => array_replace(self::$saved['mysql'], self::pick('mysql_simulation')),
            'database.connections.accounts' => array_replace(self::$saved['accounts'], self::pick('accounts_simulation')),
            'session.cookie' => config('simulation.session_cookie'),
            'cache.stores.file.path' => storage_path('framework/cache/data-simulation'),
            'permission.cache.key' => 'spatie.permission.cache.simulation',
            'mail.default' => 'array',
            'queue.default' => 'sync',
        ]);
        $folder = (string) config('simulation.storage_folder');
        foreach (self::$saved['disks'] as $name => $d) {
            if (! empty($d['root'])) {
                config(["filesystems.disks.$name.root" => rtrim($d['root'], '/\\') . DIRECTORY_SEPARATOR . $folder]);
            }
            if (! empty($d['url'])) {
                config(["filesystems.disks.$name.url" => rtrim($d['url'], '/') . '/' . $folder]);
            }
        }
        self::$current = self::SIMULATION;
        self::rebuild();
        // nothing leaves the simulation: every outside HTTP call is answered with an empty reply instead of being made
        Http::fake();
    }

    private static function toProduction(): void
    {
        if (self::$saved) {
            config([
                'database.connections.mysql' => self::$saved['mysql'], 'database.connections.accounts' => self::$saved['accounts'], 'session.cookie' => self::$saved['session.cookie'],
                'cache.stores.file.path' => self::$saved['cache.path'], 'permission.cache.key' => self::$saved['permission.key'], 'mail.default' => self::$saved['mail.default'], 'queue.default' => self::$saved['queue'],
            ]);
            foreach (self::$saved['disks'] as $name => $d) {
                foreach ($d as $k => $v) {
                    config(["filesystems.disks.$name.$k" => $v]);
                }
            }
        }
        self::$current = self::PRODUCTION;
        self::rebuild();
    }

    private static function pick(string $connection): array
    {
        return array_intersect_key(config('database.connections.' . $connection), array_flip(['host', 'port', 'database', 'username', 'password', 'unix_socket']));
    }

    /** Drop everything that was built from the previous environment's settings. */
    private static function rebuild(): void
    {
        foreach (['mysql', 'accounts', 'mysql_simulation', 'accounts_simulation'] as $c) {
            DB::purge($c);
        }
        Cache::forgetDriver('file');
        Cache::forgetDriver();
        Storage::forgetDisk(array_keys((array) config('filesystems.disks')));
        if (app()->resolved(\Spatie\Permission\PermissionRegistrar::class)) {
            $r = app(\Spatie\Permission\PermissionRegistrar::class);
            $r->initializeCache();
            $r->clearPermissionsCollection();
        }
        // anything that already holds a connection, a session handler, a cache store or a limiter from the OTHER environment is dropped, so it is built again from the new settings
        if (app()->resolved('session')) {
            app('session')->forgetDrivers();
        }
        Cache::forgetDriver(array_keys((array) config('cache.stores')));
        foreach (['session.store', 'cache.store'] as $abstract) {
            app()->forgetInstance($abstract);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance($abstract);
        }
        if (app()->resolved('auth')) {
            app('auth')->forgetGuards();
        }
        if (app()->resolved(\Illuminate\Cache\RateLimiter::class)) {
            // the limiters registered at boot stay; their counters move to the new environment's cache
            $limiter = app(\Illuminate\Cache\RateLimiter::class);
            $prop = new \ReflectionProperty($limiter, 'cache');
            $prop->setAccessible(true);
            $prop->setValue($limiter, Cache::store((string) config('cache.limiter', config('cache.default'))));
        }
        foreach (self::$memos as $forget) {
            $forget();
        }
    }

    /** The simulation database must really be reachable and must really be the simulation one; otherwise the request stops (it never falls back to production). */
    public static function assertConnected(): void
    {
        try {
            // a real query: this fails (and the request stops) when the database cannot be reached, and tells which database really answered
            $name = (string) DB::connection('mysql')->selectOne('select database() as d')->d;
        } catch (\Throwable $e) {
            throw new EnvironmentUnavailable('The simulation database cannot be reached.', 0, $e);
        }
        if ($name !== (string) config('database.connections.mysql_simulation.database')) {
            throw new RuntimeException('Environment mismatch.');
        }
    }

    /** Where production keeps the files of a storage disk (the importer copies from here into the simulation's own folder). */
    public static function productionDiskRoot(string $disk): ?string
    {
        $root = self::$saved['disks'][$disk]['root'] ?? config("filesystems.disks.$disk.root");

        return is_string($root) ? $root : null;
    }

    /** Run something on the PRODUCTION side from inside a simulation process (for example to tell the Super Admins), then return to where it was. */
    public static function runInProduction(callable $callback)
    {
        $was = self::$current;
        self::activate(self::PRODUCTION);
        try {
            return $callback();
        } finally {
            if ($was === self::SIMULATION && self::available()) {
                self::activate(self::SIMULATION);
            }
        }
    }

    /** For tests and queue workers: go back to production completely. */
    public static function reset(): void
    {
        if (self::$current !== self::PRODUCTION) {
            self::toProduction();
        }
    }
}
