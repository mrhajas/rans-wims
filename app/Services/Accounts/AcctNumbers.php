<?php

namespace App\Services\Accounts;

use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;

/** Reference numbers: ACT-2026-000001 for transactions, TRF-2026-000001 for transfers, and so on, numbered inside a lock so two requests never share one. */
class AcctNumbers
{
    /**
     * @param bool $current read the newest rows as they are NOW, not as they were when this transaction began (and wait for rows another open transaction has
     *                      written): used when a first attempt collided with a writer that had not committed yet
     */
    public static function next(string $table, string $prefix, string $column = 'reference', bool $current = false): string
    {
        return self::many($table, $prefix, 1, $column, $current)[0];
    }

    /** The next $count references in a row (a batch that is inserted together takes its numbers together). */
    public static function many(string $table, string $prefix, int $count, string $column = 'reference', bool $current = false): array
    {
        $year = now()->format('Y');

        return ReferenceLock::run("acct_{$table}", function () use ($table, $prefix, $column, $year, $current, $count) {
            $like = "{$prefix}-{$year}-";
            $len = strlen($like) + 1;
            $q = DB::connection('accounts')->table($table)->where($column, 'like', $like . '%')->selectRaw("MAX(CAST(SUBSTRING({$column}, {$len}) AS UNSIGNED)) as m");
            $max = (int) ($current ? $q->sharedLock()->value('m') : $q->value('m'));

            return array_map(fn ($i) => $like . str_pad((string) ($max + $i), 6, '0', STR_PAD_LEFT), range(1, max(1, $count)));
        });
    }
}
