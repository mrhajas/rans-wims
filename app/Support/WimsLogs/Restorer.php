<?php

namespace App\Support\WimsLogs;

use App\Models\User;
use App\Models\WimsLogArchive;
use Illuminate\Support\Facades\DB;

/**
 * Puts archived records back into their own live table, exactly as they were (every column, the same id). A record that is already live is left alone.
 * Each record put back is remembered in wims_log_restores: it is shown as "put back" in the lists and the next run does not archive it again.
 * The archive itself is never changed.
 */
class Restorer
{
    /**
     * @param  array<int, string|int>|null  $ids  these records only, or null for the whole archive
     * @return array{restored: int, already_live: int, previously: int, missing: int}
     */
    public static function restore(WimsLogArchive $arc, ?array $ids = null, ?User $by = null): array
    {
        $src = Sources::get($arc->source);
        if (! $src) {
            throw new \RuntimeException('That log is not known any more.');
        }
        $rel = (string) $arc->path;
        $only = $ids === null ? null : array_flip(array_map('strval', $ids));
        $int = ($src['pk_type'] ?? 'int') === 'int';
        $done = DB::table('wims_log_restores')->where('archive_id', $arc->id)->pluck('record_key')->flip()->all();
        $stats = ['restored' => 0, 'already_live' => 0, 'previously' => 0, 'missing' => 0];
        $seen = [];
        $chunk = [];
        $keys = [];

        $flush = function () use (&$chunk, &$keys, &$stats, $src, $arc, $by, $int) {
            if (! $chunk) {
                return;
            }
            DB::transaction(function () use (&$chunk, &$keys, &$stats, $src, $arc, $by, $int) {
                $put = (int) DB::table($src['table'])->insertOrIgnore($chunk);
                $stats['restored'] += $put;
                $stats['already_live'] += count($chunk) - $put;
                $now = now();
                $rows = [];
                foreach ($keys as $k) {
                    $rows[] = ['archive_id' => $arc->id, 'source' => $arc->source, 'record_key' => $k, 'record_num' => $int ? (int) $k : null, 'by_user_id' => $by?->id, 'by_name' => $by?->name, 'created_at' => $now];
                }
                DB::table('wims_log_restores')->insertOrIgnore($rows);
            });
            $chunk = [];
            $keys = [];
        };

        foreach (ArchiveStore::lines($rel) as $e) {
            $k = (string) $e['i'];
            if ($only !== null) {
                if (! isset($only[$k])) {
                    continue;
                }
                $seen[$k] = true;
            }
            if (isset($done[$k])) {
                $stats['previously']++;
                continue;
            }
            $row = ArchiveStore::read($rel, $e);
            if (! $row) {
                $stats['missing']++;
                continue;
            }
            $chunk[] = $row;
            $keys[] = $k;
            if (count($chunk) >= 100) {
                $flush();
            }
        }
        $flush();
        if ($only !== null) {
            $stats['missing'] += count(array_diff_key($only, $seen));
        }
        $arc->update(['restored_n' => (int) DB::table('wims_log_restores')->where('archive_id', $arc->id)->count(), 'restored_at' => now()]);
        log_activity(__('Archived records put back'), ['archive' => $arc->ref(), 'log' => $arc->source] + $stats, $by, 'Setting');

        return $stats;
    }
}
