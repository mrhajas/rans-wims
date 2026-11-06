<?php

namespace App\Console\Commands;

use App\Models\WimsLogArchive;
use App\Support\WimsLogs\ArchiveStore;
use App\Support\WimsLogs\Sources;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts the records of an archive back into their live table, exactly as they were (records that are already there are left alone). For emergencies, from the command line.
 * The archive itself is not changed or removed. Records older than the live days of their log are archived again by the next run.
 */
class WimsLogsRestore extends Command
{
    protected $signature = 'wims-logs:restore {archive : the archive number (ARC-00012 is 12)} {--id=* : only these record ids}';

    protected $description = 'Put the records of a WIMS Logs archive back into the live table';

    public function handle(): int
    {
        $arc = WimsLogArchive::query()->find((int) preg_replace('/\D/', '', (string) $this->argument('archive')));
        if (! $arc || ! ArchiveStore::exists((string) $arc->path)) {
            $this->error('That archive was not found.');

            return self::FAILURE;
        }
        $src = Sources::get($arc->source);
        if (! $src) {
            $this->error('That log is not known any more.');

            return self::FAILURE;
        }
        $only = array_map('strval', (array) $this->option('id'));
        $put = 0;
        $chunk = [];
        $flush = function () use (&$chunk, &$put, $src) {
            if ($chunk) {
                $put += DB::table($src['table'])->insertOrIgnore($chunk);
                $chunk = [];
            }
        };
        foreach (ArchiveStore::lines((string) $arc->path) as $e) {
            if ($only && ! in_array((string) $e['i'], $only, true)) {
                continue;
            }
            $row = ArchiveStore::read((string) $arc->path, $e);
            if ($row) {
                $chunk[] = $row;
            }
            if (count($chunk) >= 200) {
                $flush();
            }
        }
        $flush();
        $arc->update(['restored_n' => $arc->restored_n + $put, 'restored_at' => now()]);
        $this->info("$put record(s) put back into {$src['table']}.");

        return self::SUCCESS;
    }
}
