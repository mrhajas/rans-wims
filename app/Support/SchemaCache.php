<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Schema::hasTable() / hasColumn() ask the database's catalogue, which costs about 20 ms each and was done several times on EVERY page by the licence,
 * QR, prayer and low-stock checks. A table that exists keeps existing, so a positive answer is remembered (for the request and for a day in the cache).
 * A negative answer is never cached: the moment a migration creates the table it is seen.
 */
final class SchemaCache
{
    private static array $memo = [];

    public static function hasTable(string $table): bool
    {
        return self::$memo['t:' . $table] ??= self::remember('schema.table.' . $table, fn () => Schema::hasTable($table));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return self::$memo['c:' . $table . '.' . $column] ??= self::remember('schema.column.' . $table . '.' . $column, fn () => Schema::hasColumn($table, $column));
    }

    private static function remember(string $key, \Closure $probe): bool
    {
        try {
            if (Cache::get($key) === true) {
                return true;
            }
        } catch (\Throwable) {
            return $probe();
        }
        $has = $probe();
        if ($has) {
            try {
                Cache::put($key, true, 86400);
            } catch (\Throwable) {
            }
        }

        return $has;
    }
}
