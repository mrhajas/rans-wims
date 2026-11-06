<?php

namespace App\Console\Commands;

use App\Models\WimsLogArchive;
use App\Support\WimsLogs\ArchiveStore;
use App\Support\WimsLogs\Restorer;
use App\Support\WimsLogs\Sources;
use Illuminate\Console\Command;

/**
 * Puts the records of an archive back into their live table, exactly as they were (records that are already there are left alone). For emergencies, from the command line.
 * The archive itself is not changed or removed. Records put back stay live: the next run does not archive them again.
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
        if (! Sources::get($arc->source)) {
            $this->error('That log is not known any more.');

            return self::FAILURE;
        }
        $only = array_map('strval', (array) $this->option('id'));
        $r = Restorer::restore($arc, $only ?: null, null);
        $this->info("{$r['restored']} record(s) put back into {$arc->table_name} ({$r['already_live']} already there, {$r['previously']} put back before, {$r['missing']} not found).");

        return self::SUCCESS;
    }
}
