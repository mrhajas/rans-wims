<?php

namespace App\Console\Commands;

use App\Models\SiteDiaryMedia;
use App\Services\SiteDiary\GoogleDriveClient;
use App\Services\SiteDiary\SiteDiaryLogger;
use App\Services\SiteDiary\SiteDiarySettings;
use App\Services\SiteDiary\SiteDiaryStorage;
use Illuminate\Console\Command;

/**
 * Applies the "delete files from Google Drive after N days" setting: files older than the retention period are erased
 * from Drive; the diary, its remarks, history and the small local preview are kept. Default is forever (nothing happens).
 */
class SiteDiaryExpireMedia extends Command
{
    protected $signature = 'site-diary:expire-media {--limit=100}';

    protected $description = 'Erase Site Diary files from Google Drive once their retention period has passed';

    public function handle(SiteDiaryStorage $storage, GoogleDriveClient $drive, SiteDiarySettings $settings): int
    {
        if (! $drive->configured()) {
            $this->line('Google Drive is not connected, nothing to do.');

            return self::SUCCESS;
        }

        $total = 0;
        $accounts = SiteDiaryMedia::withoutGlobalScope('account')->whereNotNull('drive_file_id')->distinct()->pluck('account_id');
        foreach ($accounts as $accountId) {
            $days = (int) $settings->forAccount($accountId)->get('drive_retention_days');
            if ($days < 1) {
                continue;
            }
            SiteDiaryMedia::withoutGlobalScope('account')->withTrashed()
                ->where('account_id', $accountId)->whereNotNull('drive_file_id')->whereNull('expired_at')->whereNull('purged_at')
                ->where('upload_status', 'uploaded')
                ->whereRaw('COALESCE(uploaded_at, created_at) < ?', [now()->subDays($days)])
                ->orderBy('id')->limit((int) $this->option('limit'))->get()
                ->each(function (SiteDiaryMedia $m) use ($storage, &$total) {
                    if ($storage->expire($m)) {
                        $total++;
                        SiteDiaryLogger::log(null, $m->diary, 'FILE_EXPIRED', $m);
                    }
                });
        }
        $this->info("Erased {$total} file(s) from Google Drive.");

        return self::SUCCESS;
    }
}
