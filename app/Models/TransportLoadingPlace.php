<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A place other than a Project / site where loading / unloading was done (Head Office, Workshop, Port...). Grows as people type new ones. */
class TransportLoadingPlace extends Eloquent
{
    protected $guarded = [];

    /** Tidy a typed place: trimmed, single spaces, 80 characters at most; null when empty. */
    public static function clean(?string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));

        return $name === '' ? null : mb_substr($name, 0, 80);
    }

    /** Saves a typed place for next time (matching an existing one ignoring case keeps its spelling) and returns the name to store. */
    public static function remember(?string $name, ?int $userId = null): ?string
    {
        $name = self::clean($name);
        if ($name === null) {
            return null;
        }
        $existing = self::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing->name;
        }

        return self::create(['name' => $name, 'created_by_user_id' => $userId])->name;
    }

    public static function names(): array
    {
        return self::query()->orderBy('name')->pluck('name')->all();
    }
}
