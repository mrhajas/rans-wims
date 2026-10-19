<?php

namespace App\Console\Commands;

use App\Services\FeatureManager;
use App\Services\Weather\WeatherAccess;
use App\Services\Weather\WeatherManager;
use App\Services\Weather\WeatherRiskService;
use App\Services\Weather\WeatherSettings;
use Illuminate\Console\Command;

/**
 * Every 30 minutes: for each active project that has coordinates, refresh its weather only where the saved forecast (or alerts) has expired,
 * one provider call per place (projects at the same place share it), then evaluate the thresholds and raise / tidy the warnings. A provider
 * that is failing or data that has gone stale is reported once per project per six hours. Safe to run twice.
 */
class WeatherRefresh extends Command
{
    protected $signature = 'weather:refresh {--project= : Only this project id} {--force : Fetch even when the saved data is still fresh} {--dry-run : List what would be fetched, call nothing}';

    protected $description = 'Refresh weather for active projects with a location, then evaluate the weather-sensitive thresholds';

    public function handle(WeatherManager $manager, WeatherRiskService $risk): int
    {
        if (! FeatureManager::isEnabled('weather_risk')) {
            $this->info('Weather & Site Risk is switched off.');

            return self::SUCCESS;
        }
        $fetched = 0;
        $evaluated = 0;
        $skipped = 0;
        $seenPlaces = [];
        $projects = WeatherAccess::scheduledProjects()->when($this->option('project'), fn ($q, $id) => $q->where('id', $id))->orderBy('id')->get();
        foreach ($projects as $w) {
            $conf = $manager->configuration((int) $w->account_id);
            if (! $conf['ok']) {
                $skipped++;
                continue;
            }
            $state = $manager->state($w);
            $place = $w->account_id . '|' . ($state['location']['key'] ?? '');
            if ($this->option('dry-run')) {
                $this->line($w->name . ': ' . $state['status'] . ($manager->needsFetch($state, 'schedule') || $this->option('force') ? ' -> would fetch' : ' (fresh)') . (isset($seenPlaces[$place]) ? ' (shares a call)' : ''));
                $seenPlaces[$place] = true;
                continue;
            }
            $force = (bool) $this->option('force') && ! isset($seenPlaces[$place]); // one forced call per place
            $state = $manager->ensure($w, 'schedule', null, $force);
            $seenPlaces[$place] = true;
            $fetched += ($state['fetched'] ?? false) ? 1 : 0;
            $r = $risk->evaluate($w, $state);
            if ($r['skipped'] === null) {
                $evaluated++;
            }
            if (in_array($state['status'], ['failed', 'stale'], true)) {
                $risk->reportUnavailable($w, $state); // once per project per six hours
            }
        }
        if (! $this->option('dry-run')) {
            foreach (array_unique($projects->pluck('account_id')->all()) as $accountId) {
                $manager->prune((int) $accountId);
            }
        }
        $this->info("Projects: {$projects->count()}, provider calls made: {$fetched}, evaluated: {$evaluated}, not configured: {$skipped}.");

        return self::SUCCESS;
    }
}
