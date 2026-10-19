<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ReferenceLock
{
    /**
     * Serialise "read MAX(reference) + 1, then insert" so two concurrent requests can't
     * generate the same next number. Falls through (unlocked) if the lock can't be
     * acquired in time, so a stuck lock never blocks record creation outright.
     */
    public static function run(string $key, callable $callback, int $timeoutSeconds = 10)
    {
        $name = 'wims_ref_' . $key;
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeoutSeconds])->acquired ?? 0) === 1;

        try {
            return $callback();
        } finally {
            if ($acquired) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }
}
