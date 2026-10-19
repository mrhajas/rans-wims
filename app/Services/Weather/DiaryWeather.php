<?php

namespace App\Services\Weather;

use App\Models\SiteDiary;
use App\Models\User;
use App\Models\WeatherObservation;
use App\Models\WeatherSnapshot;
use App\Models\WeatherWarning;
use Carbon\CarbonImmutable;

/** The small Weather panel on a Site Diary: what was observed on site that day, and the forecast that was saved for it, kept apart and labelled. */
class DiaryWeather
{
    public static function panel(SiteDiary $diary, User $user): ?array
    {
        if (! $user->can('read-weather') || ! WeatherAccess::feature('weather_risk', $user) || ! WeatherAccess::canSeeProject($user, (int) $diary->warehouse_id)) {
            return null;
        }
        $date = $diary->diary_date->toDateString();
        $w = $diary->warehouse;
        $accountId = (int) $diary->account_id;
        $presenter = app(WeatherPresenter::class);

        $obs = WeatherObservation::query()->where('account_id', $accountId)->where('warehouse_id', $diary->warehouse_id)
            ->where(fn ($q) => $q->whereDate('observed_at', $date)->orWhere('site_diary_id', $diary->id))->with(['recorder:id,name', 'workPackage:id,title', 'warehouse:id,name'])->orderBy('observed_at')->get();

        $forecast = null;
        $loc = $w ? WeatherManager::coordinates($w) : null;
        if ($loc && WeatherAccess::feature('weather_observations', $user)) {
            $day = CarbonImmutable::parse($date);
            $base = WeatherSnapshot::query()->where('account_id', $accountId)->where('location_key', $loc['key'])->where('kind', 'forecast')->where('status', 'ok');
            $snap = (clone $base)->where('fetched_at', '<=', $day->startOfDay())->orderByDesc('fetched_at')->first();
            $timing = 'issued before the day';
            if (! $snap) {
                $snap = (clone $base)->whereBetween('fetched_at', [$day->startOfDay(), $day->endOfDay()])->orderBy('fetched_at')->first();
                $timing = 'issued during the day';
            }
            $summary = $snap ? WeatherReportBuilder::forecastSummary($snap->payload, $date) : '';
            $forecast = $snap && $summary !== '' ? ['summary' => $summary, 'provider' => $snap->provider, 'fetched_at' => $snap->fetched_at->toDateTimeString(), 'timing' => $timing, 'uncertainty' => $snap->payload['uncertainty'] ?? null] : null;
        }

        $warnings = WeatherWarning::query()->where('account_id', $accountId)->where('warehouse_id', $diary->warehouse_id)
            ->where(fn ($q) => $q->whereDate('raised_at', $date)->orWhere(fn ($x) => $x->whereDate('window_start', '<=', $date)->whereDate('window_end', '>=', $date)))
            ->whereIn('status', ['open', 'acknowledged', 'expired'])->orderBy('raised_at')->limit(6)->get(['id', 'title', 'status', 'severity', 'window_start', 'window_end'])
            ->map(fn ($x) => ['id' => $x->id, 'title' => $x->title, 'status' => $x->status, 'severity' => $x->severity, 'window_start' => optional($x->window_start)->toDateTimeString(), 'window_end' => optional($x->window_end)->toDateTimeString()])->all();

        return [
            'date' => $date, 'observations' => $obs->map(fn ($o) => $presenter->observation($o))->values()->all(), 'forecast' => $forecast, 'warnings' => $warnings,
            'can_record' => $user->can('record-weather-observations') && WeatherAccess::feature('weather_observations', $user),
            'record_url' => route('weather.observations.index', ['project' => $diary->warehouse_id, 'date' => $date, 'record' => 1]),
            'overview_url' => route('weather.overview', ['project' => $diary->warehouse_id]),
        ];
    }
}
