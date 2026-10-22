<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Tighten\Ziggy\Ziggy;

/**
 * The route table the browser needs for route('name') calls, served as its own cacheable script instead of being written into every page.
 * It used to be about 245 KB of text inside every full page load; now the browser downloads it once per release (the address carries a version) and reuses it.
 */
class ZiggyScript
{
    private static ?string $version = null;

    /** Changes whenever the routes change: the date of the cached route file after a deploy, otherwise a fingerprint of the table itself (development). */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }
        $cached = base_path('bootstrap/cache/routes-v7.php');
        $fingerprint = is_file($cached) ? filemtime($cached) . '-' . filesize($cached) : md5(self::json());

        return self::$version = substr(md5($fingerprint . '|' . request()->getSchemeAndHttpHost()), 0, 16);
    }

    public static function json(): string
    {
        return (new Ziggy())->toJson();
    }

    /** The script itself (the JSON is kept in the cache per version, so it is built once). */
    public static function body(): string
    {
        return Cache::remember('ziggy-script:' . self::version(), now()->addDays(30), fn () => 'const Ziggy=' . self::json() . ';');
    }

    /** What goes in the page head in place of the inline table. */
    public static function tag(): string
    {
        return '<script src="' . e(route('ziggy.script', ['v' => self::version()])) . '"></script>';
    }
}
