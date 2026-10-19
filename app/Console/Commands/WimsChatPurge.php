<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\WimsChat\WimsChatFiles;
use App\Services\WimsChat\WimsChatLogger;
use App\Services\WimsChat\WimsChatSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the two WIMSChat retention settings (default 90 days each, 0 = forever):
 *  - messages older than "message retention" are deleted for good (with their reactions, mentions and attachment records);
 *  - files / images / voice notes older than "attachment retention" are erased from the server (the record stays).
 */
class WimsChatPurge extends Command
{
    protected $signature = 'wimschat:purge {--dry-run : only count what would be deleted}';

    protected $description = 'Delete WIMSChat messages and attachment files older than the configured retention periods';

    public function handle(WimsChatFiles $files, WimsChatSettings $settings): int
    {
        $messages = 0;
        $attachments = 0;
        foreach (Account::pluck('id') as $accountId) {
            $cfg = $settings->forAccount($accountId);
            $mDays = (int) $cfg->get('message_retention_days');
            $aDays = (int) $cfg->get('attachment_retention_days');
            $before = $messages;

            if ($mDays > 0) {
                $cutoff = now()->subDays($mDays);
                if ($this->option('dry-run')) {
                    $messages += DB::table('wimschat_messages')->where('account_id', $accountId)->where('created_at', '<', $cutoff)->count();
                } else {
                    $messages += $this->purgeMessages($accountId, $cutoff);
                }
            }
            $accountMessages = $messages - $before;
            $accountFiles = 0;
            if ($aDays > 0 && ! $this->option('dry-run')) {
                for ($i = 0; $i < 20; $i++) {
                    $n = $files->purgeOlderThan($aDays);
                    $accountFiles += $n;
                    if ($n === 0) {
                        break;
                    }
                }
            }
            $attachments += $accountFiles;
            if (($accountMessages || $accountFiles) && ! $this->option('dry-run')) {
                WimsChatLogger::log(null, 'RETENTION_PURGE', null, ['account_id' => $accountId, 'messages' => $accountMessages, 'attachment_records' => $accountFiles]);
            }
        }

        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ')."{$messages} message(s); erased files of {$attachments} attachment record(s).");

        return self::SUCCESS;
    }

    private function purgeMessages(int $accountId, $cutoff): int
    {
        $total = 0;
        $disk = app(WimsChatFiles::class)->disk();
        while (true) {
            $ids = DB::table('wimschat_messages')->where('account_id', $accountId)->where('created_at', '<', $cutoff)->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $att = DB::table('wimschat_attachments')->whereIn('message_id', $ids)->get(['stored_path', 'thumb_path']);
            $convs = DB::table('wimschat_messages')->whereIn('id', $ids)->distinct()->pluck('conversation_id');

            DB::transaction(function () use ($ids, $convs) {
                DB::table('wimschat_messages')->whereIn('id', $ids)->update(['reply_to_id' => null]); // keep replies pointing nowhere rather than dangling
                DB::table('wimschat_messages')->whereIn('reply_to_id', $ids)->update(['reply_to_id' => null]);
                DB::table('wimschat_messages')->whereIn('id', $ids)->delete(); // reactions, mentions and attachment rows go with them (FK cascade)
                foreach ($convs as $cid) {
                    $last = DB::table('wimschat_messages')->where('conversation_id', $cid)->orderByDesc('id')->first(['id', 'created_at']);
                    DB::table('wimschat_conversations')->where('id', $cid)->update(['last_message_id' => $last->id ?? null, 'last_message_at' => $last->created_at ?? null]);
                }
            });

            // files that no remaining record uses (a forwarded copy may still share one)
            foreach ($att->pluck('stored_path')->unique() as $path) {
                if (! DB::table('wimschat_attachments')->where('stored_path', $path)->exists()) {
                    $disk->delete($path);
                }
            }
            foreach ($att->pluck('thumb_path')->filter()->unique() as $thumb) {
                if (! DB::table('wimschat_attachments')->where('thumb_path', $thumb)->exists()) {
                    $disk->delete($thumb);
                }
            }
            $total += $ids->count();
        }

        return $total;
    }
}
