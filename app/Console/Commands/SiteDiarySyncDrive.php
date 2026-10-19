<?php

namespace App\Console\Commands;

use App\Models\SiteDiaryMedia;
use App\Services\SiteDiary\GoogleDriveClient;
use App\Models\SiteDiaryNotificationRule as Rule;
use App\Services\SiteDiary\SiteDiaryLogger;
use App\Services\SiteDiary\SiteDiaryNotifier;
use App\Services\SiteDiary\SiteDiaryStorage;
use Illuminate\Console\Command;

/**
 * Moves Site Diary files that are still on the private local disk (waiting, failed, or uploaded while Drive was off)
 * to Google Drive. Safe to run repeatedly; cron-friendly (no queue worker needed).
 */
class SiteDiarySyncDrive extends Command
{
    protected $signature = 'site-diary:sync-drive {--limit=15}';

    protected $description = 'Upload waiting / failed Site Diary media to Google Drive';

    public function handle(SiteDiaryStorage $storage, GoogleDriveClient $drive): int
    {
        // a request that died mid-upload leaves "uploading" behind: free it for another try
        SiteDiaryMedia::where('upload_status', 'uploading')->where('updated_at', '<', now()->subMinutes(20))->update(['upload_status' => 'pending']);

        // drafts that never received an attachment (abandoned forms) are tidied away after a day
        \App\Models\SiteDiary::where('status', 'Draft')->where('created_at', '<', now()->subDay())
            ->whereDoesntHave('media')->get()->each->forceDelete();

        // files of media that were removed from a diary while Drive could not be reached: delete them now
        \App\Models\SiteDiaryMedia::onlyTrashed()->withoutGlobalScope('account')->whereNull('purged_at')
            ->where(fn ($q) => $q->whereNotNull('drive_file_id')->orWhereNotNull('local_path')->orWhereNotNull('thumb_path'))
            ->orderBy('id')->limit(25)->get()->each(function ($m) use ($storage) {
                if (app(\App\Services\SiteDiary\SiteDiarySettings::class)->forAccount($m->account_id)->flag('delete_files_on_remove') && $storage->purge($m)) {
                    SiteDiaryLogger::log(null, $m->diary, 'FILE_PURGED', $m);
                }
            });

        if (! $drive->ready()) {
            $this->line('Google Drive is not switched on / connected, nothing to do.');

            return self::SUCCESS;
        }

        $rows = SiteDiaryMedia::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('upload_status', ['pending', 'failed'])->where('attempts', '<', 10))
                ->orWhere(fn ($q) => $q->where('storage_provider', 'local')->where('upload_status', 'uploaded')->whereNotNull('local_path')))
            ->orderBy('id')->limit((int) $this->option('limit'))->get();

        $ok = 0;
        foreach ($rows as $m) {
            if ($storage->push($m)) {
                $ok++;
                SiteDiaryLogger::log(null, $m->diary, 'DRIVE_UPLOAD', $m->refresh());
            } elseif ($m->refresh()->upload_status === 'failed' && (int) $m->attempts === 10) {
                // the sync has now given up on this file (10 tries): tell the people chosen on the Site Diary workflow page, once
                SiteDiaryNotifier::event(Rule::DRIVE_UPLOAD_FAILED, null, $m->diary, ['filename' => $m->original_name, 'changes' => (string) $m->upload_error, 'account_id' => $m->account_id]);
            }
        }
        $this->info("Synced {$ok} of {$rows->count()} file(s).");

        return self::SUCCESS;
    }
}
