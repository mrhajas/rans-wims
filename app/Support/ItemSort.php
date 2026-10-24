<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The order the item lines of a Super Adjustment are shown in (page, value report, print, PDF and Excel all use the same key):
 * az = item name A-Z, category = by category then name, high = largest physical count first, low = smallest physical count first. Anything else keeps the order they were entered in.
 */
class ItemSort
{
    public const KEYS = ['az', 'category', 'high', 'low'];

    public static function key(?string $sort): ?string
    {
        return in_array($sort, self::KEYS, true) ? $sort : null;
    }

    /** $name, $category and $qty each take one line and return its item name, its category name (or null) and its physical quantity. */
    public static function apply(Collection $lines, ?string $sort, callable $name, callable $category, callable $qty): Collection
    {
        $sort = self::key($sort);
        if (! $sort) {
            return $lines->values();
        }
        $byName = fn ($a, $b) => strnatcasecmp((string) $name($a), (string) $name($b));

        return $lines->sort(function ($a, $b) use ($sort, $byName, $category, $qty) {
            return match ($sort) {
                'az' => $byName($a, $b),
                // lines without a category go last
                'category' => ((($category($a) ?? "\u{10FFFF}") <=> ($category($b) ?? "\u{10FFFF}")) ?: $byName($a, $b)),
                'high' => ((float) $qty($b) <=> (float) $qty($a)) ?: $byName($a, $b),
                'low' => ((float) $qty($a) <=> (float) $qty($b)) ?: $byName($a, $b),
            };
        })->values();
    }
}
