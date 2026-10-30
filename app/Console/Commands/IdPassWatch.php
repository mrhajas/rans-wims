<?php

namespace App\Console\Commands;

use App\Models\IdPassCard;
use App\Models\IdPassEvent;
use App\Services\IdPass\CardAssets;
use App\Services\IdPass\IdPassNotifier;
use App\Services\IdPass\IdPassSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Daily: tell once about IDs that are about to expire and IDs that have expired, and clear the short-lived files (previews, finished batches older than 30 days). */
class IdPassWatch extends Command
{
    protected $signature = 'id-passes:watch';

    protected $description = 'RANS ID & Pass: expiry notices and clean-up of temporary files';

    public function handle(): int
    {
        $today = Carbon::today(config('app.timezone'));
        $days = IdPassSettings::noticeDays();
        $soon = 0;
        $done = 0;
        if ($days > 0) {
            IdPassCard::query()->withoutGlobalScope('account')->with('category')->where('status', IdPassCard::ACTIVE)->whereNull('expiry_notified_at')->whereNotNull('expires_at')
                ->whereBetween('expires_at', [$today->toDateString(), $today->copy()->addDays($days)->toDateString()])->orderBy('id')->chunkById(100, function ($cards) use (&$soon) {
                    foreach ($cards as $c) {
                        IdPassNotifier::fire('expiring', $c);
                        $c->forceFill(['expiry_notified_at' => now()])->saveQuietly();
                        $soon++;
                    }
                });
        }
        IdPassCard::query()->withoutGlobalScope('account')->with('category')->expired()->orderBy('id')->chunkById(100, function ($cards) use (&$done) {
            foreach ($cards as $c) {
                if (IdPassEvent::query()->where('card_id', $c->id)->where('event', 'expired')->exists()) {
                    continue;
                }
                IdPassEvent::query()->create(['card_id' => $c->id, 'event' => 'expired', 'from_status' => 'Active', 'to_status' => 'Expired', 'detail' => ['expires' => $c->expires_at->toDateString()]]);
                IdPassNotifier::fire('expired', $c);
                $done++;
            }
        });
        // temporary files: previews of the issue form after a day, finished batch PDFs after 30 days
        foreach (['tmp' => 1, 'batches' => 30] as $sub => $age) {
            foreach (glob(CardAssets::dir($sub) . '/*') ?: [] as $f) {
                if (is_file($f) && filemtime($f) < now()->subDays($age)->getTimestamp()) {
                    @unlink($f);
                }
            }
        }
        $this->info("Expiring soon told: {$soon}. Expired told: {$done}.");

        return self::SUCCESS;
    }
}
