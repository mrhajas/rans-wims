<?php

namespace App\Support\Performance;

use App\Services\SiteDiary\SiteDiaryLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves old activity history out of the live activity log into activity_log_archive. Nothing is deleted: the row keeps its id and every value, the archive can be
 * browsed and downloaded in Settings > Performance & Data, and "how many months stay in the live log" is a setting. Site Diary history stays where it is.
 */
class ActivityArchiver
{
    private const BATCH = 2000;

    public static function cutoff(?int $months = null): ?\Illuminate\Support\Carbon
    {
        $months = $months ?? PerfSettings::get('perf_activity_keep_months');

        return $months > 0 ? now()->subMonths($months) : null;
    }

    /** How many rows are old enough to move (without moving them). */
    public static function due(?int $months = null): int
    {
        $cut = self::cutoff($months);
        if (! $cut || ! \App\Support\Performance\Tables::has('activity_log_archive')) {
            return 0;
        }

        return (int) self::eligible($cut)->count();
    }

    /** @return array{moved:int, cutoff:?string, batches:int} */
    public static function run(?int $months = null, int $maxRows = 500000): array
    {
        $cut = self::cutoff($months);
        if (! $cut || ! \App\Support\Performance\Tables::has('activity_log_archive')) {
            return ['moved' => 0, 'cutoff' => null, 'batches' => 0];
        }

        $columns = Schema::getColumnListing('activity_log');
        $list = '`' . implode('`,`', $columns) . '`';
        $moved = 0;
        $batches = 0;

        while ($moved < $maxRows) {
            $ids = self::eligible($cut)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if (! $ids) {
                break;
            }
            DB::transaction(function () use ($ids, $list) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                // IGNORE: a row that is already in the archive (a run that was cut short) is not copied twice, but it is still taken out of the live log
                DB::insert("INSERT IGNORE INTO activity_log_archive ({$list}, archived_at) SELECT {$list}, NOW() FROM activity_log WHERE id IN ({$marks})", $ids);
                DB::table('activity_log')->whereIn('id', $ids)->delete();
            });
            $moved += count($ids);
            $batches++;
        }

        return ['moved' => $moved, 'cutoff' => $cut->toDateString(), 'batches' => $batches];
    }

    private static function eligible(\Illuminate\Support\Carbon $cut)
    {
        return DB::table('activity_log')
            ->where('created_at', '<', $cut)
            ->where(function ($q) {
                $q->whereNull('log_name')->orWhere('log_name', '!=', SiteDiaryLogger::LOG_NAME);
            });
    }
}
