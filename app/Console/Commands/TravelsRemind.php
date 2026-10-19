<?php

namespace App\Console\Commands;

use App\Models\Travel;
use App\Services\Travels\TravelNotifier;
use Illuminate\Console\Command;

/** Tells the people on an approved travel (and the people set on the Alerts page) that it leaves in a few days. Each travel is reminded once per reminder day. */
class TravelsRemind extends Command
{
    protected $signature = 'travels:remind {--dry : Show what would be sent without sending}';

    protected $description = 'Send the "Departing soon" alert for approved travels, on the days set on the Travels settings page';

    public function handle(): int
    {
        $days = Travel::remindDays();
        if (! $days) {
            $this->info('No reminder days are set.');

            return self::SUCCESS;
        }
        $sent = 0;
        foreach ($days as $n) {
            $date = now()->addDays($n)->toDateString();
            Travel::query()->withoutGlobalScopes()->where('status', Travel::APPROVED)->whereDate('depart_date', $date)->with(['travellers', 'ticketAgent:id,name', 'visaAgent:id,name', 'user:id,name'])->get()->each(function (Travel $t) use ($n, &$sent) {
                $extra = $t->extra_attributes->all();
                $done = (array) ($extra['reminded_days'] ?? []);
                if (in_array($n, $done, true)) {
                    return;
                }
                $this->line("{$t->reference}: leaves in {$n} day(s)");
                if (! $this->option('dry')) {
                    TravelNotifier::fire('departing_soon', $t, null, '', ['days' => $n]);
                    $extra['reminded_days'] = array_values(array_unique(array_merge($done, [$n])));
                    $t->forceFill(['extra_attributes' => $extra])->saveQuietly();
                    log_activity('Travel ' . $t->reference . ' reminder sent', ['days' => $n], $t, 'Travels');
                }
                $sent++;
            });
        }
        $this->info($this->option('dry') ? "{$sent} reminder(s) would be sent." : "{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
