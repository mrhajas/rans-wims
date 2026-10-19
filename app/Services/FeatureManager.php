<?php

namespace App\Services;

use App\Models\SystemFeature;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Central authority for "is this WIMS module currently switched on".
 * Backed by the system_features table, cached forever and refreshed
 * explicitly whenever a Super Admin toggles a feature (see
 * SystemFeatureController::update()) — never queried more than once
 * per cache lifetime, matching the spec's caching requirement.
 */
class FeatureManager
{
    public const CACHE_KEY = 'system_features.map';

    public static function isEnabled(string $key): bool
    {
        return (bool) (self::enabledMap()[$key] ?? true);
    }

    public static function canAccess(string $key, ?User $user = null): bool
    {
        if ($user && $user->hasRole('Super Admin')) {
            return true;
        }

        return self::isEnabled($key);
    }

    /**
     * Which registered feature (if any) owns the given route name, matched
     * by longest route-prefix hit. Routes matching no feature return null
     * and are never gated — unmapped routes always pass through.
     */
    public static function featureForRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach (self::routePrefixMap() as $key => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (Str::startsWith($routeName, $prefix) && strlen($prefix) > $bestLength) {
                    $best = $key;
                    $bestLength = strlen($prefix);
                }
            }
        }

        return $best;
    }

    /** Per-request memo on top of the forever-cache — FeatureGate + HandleInertiaRequests both call these 1-3x per request. */
    private static ?array $enabledMapMemo = null;
    private static ?array $routePrefixMapMemo = null;

    /**
     * Effective on/off map: a function is on only while its own switch is on AND the function it depends on (depends_on) is on, all the
     * way up the chain. So switching a parent off switches its sub-functions off everywhere (route gate, server checks, the menu and the
     * Launcher). A dependency that names a function with no row is ignored.
     */
    public static function enabledMap(): array
    {
        return self::$enabledMapMemo ??= Cache::rememberForever(self::CACHE_KEY, function () {
            $rows = SystemFeature::query()->get(['key', 'is_enabled', 'depends_on']);
            $own = $rows->pluck('is_enabled', 'key')->map(fn ($v) => (bool) $v)->all();
            $parent = $rows->pluck('depends_on', 'key')->all();
            $effective = [];
            foreach ($own as $key => $on) {
                $seen = [$key => true];
                $cursor = $key;
                while ($on && ($dep = $parent[$cursor] ?? null) && array_key_exists($dep, $own) && ! isset($seen[$dep])) {
                    $on = $own[$dep];
                    $seen[$dep] = true;
                    $cursor = $dep;
                }
                $effective[$key] = $on;
            }

            return $effective;
        });
    }

    /** The function a sub-function depends on, or null. */
    public static function parentOf(string $key): ?string
    {
        return SystemFeature::query()->where('key', $key)->value('depends_on');
    }

    public static function routePrefixMap(): array
    {
        return self::$routePrefixMapMemo ??= Cache::rememberForever(self::CACHE_KEY.'.routes', function () {
            return SystemFeature::query()->pluck('route_prefixes', 'key')
                ->map(fn ($prefixes) => $prefixes ?? [])
                ->all();
        });
    }

    public static function flushCache(): void
    {
        self::$enabledMapMemo = null;
        self::$routePrefixMapMemo = null;
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.'.routes');
    }
}
