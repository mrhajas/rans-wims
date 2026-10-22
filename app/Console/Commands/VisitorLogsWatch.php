<?php

namespace App\Console\Commands;

use App\Models\VisitorIncomingItem;
use App\Models\VisitorLogEvent;
use App\Models\VisitorVisit;
use App\Services\FeatureManager;
use App\Services\VisitorLogs\VisitorNotifier;
use App\Services\VisitorLogs\VisitorSettings;
use Illuminate\Console\Command;

/**
 * Every 30 minutes: a visitor who has been checked in for longer than the hours on the Visitor Logs settings, and an item that has waited for its recipient longer than the
 * days set there, each cause ONE informational alert (it never assumes the visitor is missing). Safe to run twice: an alert is marked on the record.
 */
class VisitorLogsWatch extends Command
{
    protected $signature = 'visitor-logs:watch {--dry-run : List what would be sent without sending it}';

    protected $description = 'Alert about visitors still on the premises and items waiting too long';

    public function handle(): int
    {
        if (! FeatureManager::isEnabled('visitor_logs')) {
            $this->info('Visitor Logs is switched off.');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        $hours = VisitorSettings::overstayHours();
        $days = VisitorSettings::collectionDays();
        $visits = $hours > 0 ? VisitorVisit::query()->where('status', VisitorVisit::IN)->whereNull('long_stay_alerted_at')->where('check_in_at', '<=', now()->subHours($hours))->get() : collect();
        foreach ($visits as $v) {
            $this->line(($dry ? '[dry] ' : '') . 'Still on premises: ' . $v->reference . ' since ' . $v->check_in_at->format('d M H:i'));
            if (! $dry) {
                $v->forceFill(['long_stay_alerted_at' => now()])->saveQuietly();
                VisitorLogEvent::record('visit', $v, 'long_stay_alert', [], ['hours' => $hours]);
                VisitorNotifier::visit('visit_long_stay', $v);
            }
        }
        $items = $days > 0 ? VisitorIncomingItem::query()->open()->whereNull('overdue_alerted_at')->where('received_at', '<=', now()->subDays($days))->get() : collect();
        foreach ($items as $i) {
            $this->line(($dry ? '[dry] ' : '') . 'Waiting too long: ' . $i->reference . ' since ' . $i->received_at->format('d M H:i'));
            if (! $dry) {
                $i->forceFill(['overdue_alerted_at' => now()])->saveQuietly();
                VisitorLogEvent::record('item', $i, 'overdue_alert', [], ['days' => $days]);
                VisitorNotifier::item('item_collection_overdue', $i);
            }
        }
        $this->info($visits->count() . ' visitor alert(s), ' . $items->count() . ' item alert(s).');

        return self::SUCCESS;
    }
}
