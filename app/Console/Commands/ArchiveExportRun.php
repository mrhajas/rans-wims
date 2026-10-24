<?php

namespace App\Console\Commands;

use App\Support\Performance\ArchiveExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveExportRun extends Command
{
    protected $signature = 'archive:export-run {--seconds=40}';
    protected $description = 'Makes the next parts of the large activity-archive PDF exports that people asked for (a few parts per run, resumes next minute)';

    public function handle(): int
    {
        $deadline = microtime(true) + (int) $this->option('seconds');
        while (microtime(true) < $deadline) {
            $e = DB::table('activity_archive_exports')->whereIn('status', ['queued', 'running'])->orderBy('id')->first();
            if (! $e) {
                break;
            }
            try {
                ArchiveExport::work($e, max(5, (int) ($deadline - microtime(true))));
            } catch (\Throwable $ex) {
                DB::table('activity_archive_exports')->where('id', $e->id)->update(['status' => 'failed', 'error' => mb_substr($ex->getMessage(), 0, 500), 'updated_at' => now()]);
                report($ex);
            }
        }

        return self::SUCCESS;
    }
}
