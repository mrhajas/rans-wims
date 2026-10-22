<?php

namespace App\Services\Weather;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeatherObservation;
use App\Models\WeatherWarning;

/** The shape the Weather pages (and the Site Diary panel) receive: always labelled by where the numbers come from and when. */
class WeatherPresenter
{
    /** @var array<int,array>|null open warnings per project and the newest observation per project, loaded for all the cards of a page at once */
    private ?array $warningsMemo = null;

    private ?array $observedMemo = null;

    public function __construct(private WeatherManager $manager, private WeatherRiskService $risk)
    {
    }

    /** Loads what every card needs in a few queries (instead of several per card): warnings, newest site observation, scheduled work. */
    public function warm(\Illuminate\Support\Collection $projects): void
    {
        $ids = $projects->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (! $ids) {
            return;
        }
        $account = (int) ($projects->first()->account_id ?: 1);
        $this->warningsMemo = array_fill_keys($ids, []);
        foreach (WeatherWarning::query()->where('account_id', $account)->whereIn('warehouse_id', $ids)->whereIn('status', [WeatherWarning::OPEN, WeatherWarning::ACKNOWLEDGED])
            ->where(fn ($q) => $q->whereNull('window_end')->orWhere('window_end', '>=', now()))->orderByRaw("FIELD(status, 'open', 'acknowledged')")->orderByDesc('raised_at')->get()->groupBy('warehouse_id') as $wid => $rows) {
            $this->warningsMemo[(int) $wid] = $rows->take(8)->map(fn (WeatherWarning $x) => $this->warningRow($x))->all();
        }
        $this->observedMemo = array_fill_keys($ids, null);
        foreach (WeatherObservation::query()->where('account_id', $account)->whereIn('warehouse_id', $ids)->where('observed_at', '>=', now()->subDay())->with(['recorder:id,name', 'warehouse:id,name', 'workPackage:id,title'])->orderByDesc('observed_at')->get()->groupBy('warehouse_id') as $wid => $rows) {
            $this->observedMemo[(int) $wid] = $this->observation($rows->first());
        }
        $this->risk->warmWork($ids, $account);
        $this->manager->warm($projects, $account);
    }

    private function warningRow(WeatherWarning $x): array
    {
        return [
            'id' => $x->id, 'title' => $x->title, 'severity' => $x->severity, 'status' => $x->status, 'kind' => $x->kind, 'message' => $x->message,
            'window_start' => optional($x->window_start)->toDateTimeString(), 'window_end' => optional($x->window_end)->toDateTimeString(),
        ];
    }

    public function card(Warehouse $w, User $user): array
    {
        $state = $this->manager->state($w);
        $prefs = WeatherSettings::get((int) $w->account_id);
        $hourly = $state['forecast']['hourly'] ?? [];
        $daily = $state['forecast']['daily'] ?? [];
        $can = [
            'location' => $user->can('manage-weather-locations') && WeatherAccess::feature('weather_locations', $user),
            'record' => $user->can('record-weather-observations') && WeatherAccess::feature('weather_observations', $user),
            'acknowledge' => $user->can('acknowledge-weather-warnings') && WeatherAccess::feature('weather_warnings', $user),
            // the project form (Projects / Warehouses > Edit) holds the address and coordinates
            'edit_project' => $user->can('update-warehouses'),
        ];
        $usable = ! empty($hourly) && ! $state['stale'];

        return [
            'id' => $w->id, 'name' => $w->name, 'code' => $w->code, 'category' => trim((string) $w->status) ?: 'Other', 'address' => $w->address, 'stage' => $w->stage, 'project_manager' => $w->project_manager,
            'latitude' => $w->latitude !== null ? (float) $w->latitude : null, 'longitude' => $w->longitude !== null ? (float) $w->longitude : null,
            'status' => $state['status'], 'message' => $state['message'], 'provider' => $state['provider'], 'provider_label' => $state['provider_label'], 'supports_alerts' => $state['supports_alerts'],
            'fetched_at' => $state['fetched_at'], 'age_minutes' => $state['age_minutes'], 'stale' => $state['stale'], 'due' => $state['due'], 'current_at' => $state['current_at'], 'current_due' => $state['current_due'],
            'last_error' => $state['last_error'], 'location_key' => $state['location']['key'] ?? null,
            'current' => $state['current'], 'hours' => array_slice($hourly, 0, 36), 'days' => array_slice($daily, 0, 7),
            'alerts' => $state['alerts'], 'alerts_supported' => $state['supports_alerts'], 'uncertainty' => $state['uncertainty'],
            'activities' => $usable ? $this->risk->assessments($w, $state) : [],
            'warnings' => $this->openWarnings($w),
            'observed' => $this->latestObserved($w),
            'cooldown' => $this->manager->cooldownRemaining($w),
            'needs_fetch' => $prefs['refresh_on_view'] && $this->manager->needsFetch($state, 'view'),
            'can' => $can,
        ];
    }

    public function openWarnings(Warehouse $w): array
    {
        if ($this->warningsMemo !== null && array_key_exists($w->id, $this->warningsMemo)) {
            return $this->warningsMemo[$w->id];
        }

        return WeatherWarning::query()->where('account_id', $w->account_id)->where('warehouse_id', $w->id)->whereIn('status', [WeatherWarning::OPEN, WeatherWarning::ACKNOWLEDGED])
            ->where(fn ($q) => $q->whereNull('window_end')->orWhere('window_end', '>=', now()))->orderByRaw("FIELD(status, 'open', 'acknowledged')")->orderByDesc('raised_at')->limit(8)->get()
            ->map(fn (WeatherWarning $x) => [
                'id' => $x->id, 'title' => $x->title, 'severity' => $x->severity, 'status' => $x->status, 'kind' => $x->kind, 'message' => $x->message,
                'window_start' => optional($x->window_start)->toDateTimeString(), 'window_end' => optional($x->window_end)->toDateTimeString(),
            ])->all();
    }

    /** The newest weather observed on site in the last 24 hours (never mixed with provider data). */
    public function latestObserved(Warehouse $w): ?array
    {
        if ($this->observedMemo !== null && array_key_exists($w->id, $this->observedMemo)) {
            return $this->observedMemo[$w->id];
        }
        $o = WeatherObservation::query()->where('account_id', $w->account_id)->where('warehouse_id', $w->id)->where('observed_at', '>=', now()->subDay())->with('recorder:id,name')->orderByDesc('observed_at')->first();

        return $o ? $this->observation($o) : null;
    }

    public function observation(WeatherObservation $o): array
    {
        $opt = WeatherSettings::options();

        return [
            'id' => $o->id, 'warehouse_id' => $o->warehouse_id, 'project' => optional($o->warehouse)->name, 'observed_at' => $o->observed_at->format('Y-m-d H:i:s'),
            'conditions' => $o->conditions, 'conditions_label' => $opt['conditions'][$o->conditions] ?? $o->conditions,
            'rainfall_level' => $o->rainfall_level, 'rainfall_label' => $o->rainfall_level ? ($opt['rainfall'][$o->rainfall_level] ?? $o->rainfall_level) : null, 'rainfall_mm' => $o->rainfall_mm,
            'temperature_c' => $o->temperature_c, 'wind_level' => $o->wind_level, 'wind_label' => $o->wind_level ? ($opt['wind'][$o->wind_level] ?? $o->wind_level) : null, 'wind_kmh' => $o->wind_kmh,
            'notes' => $o->notes, 'work_package_id' => $o->work_package_id, 'work_package' => $o->work_package_id ? optional($o->workPackage)->title : null,
            'site_diary_id' => $o->site_diary_id, 'diary_date' => optional($o->diary_date)->toDateString(), 'recorded_by' => $o->recorded_by, 'recorder' => optional($o->recorder)->name,
            'created_at' => optional($o->created_at)->toDateTimeString(),
        ];
    }
}
