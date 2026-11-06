<?php

namespace App\Support\WimsLogs;

use App\Models\WimsLogArchive;
use Illuminate\Http\Request;

/** What WIMS Logs has already moved out of a list, for the notice on that list's page: how many records, from when to when, how long they stay, and whether this person may read them. */
class ArchiveNotice
{
    public static function for(string $source, Request $request): ?array
    {
        try {
            $a = WimsLogArchive::query()->where('source', $source)->selectRaw('count(*) as archives, sum(rows_n - coalesce(restored_n, 0)) as rows_n, min(date_from) as date_from, max(date_to) as date_to')->first();
            if (! $a || ! (int) $a->rows_n) {
                return null;
            }

            return [
                'source' => $source, 'rows' => (int) $a->rows_n, 'archives' => (int) $a->archives, 'from' => $a->date_from, 'to' => $a->date_to,
                'keep_days' => WimsLogSettings::keepDays($source),
                'can_read' => (bool) $request->user()->can('read-wims-logs'),
            ];
        } catch (\Throwable $e) {
            return null; // the archive tables do not exist here (yet)
        }
    }
}
