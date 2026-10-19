<?php

namespace App\Services\Weather;

use App\Models\User;
use App\Models\WeatherObservation;
use App\Models\WeatherSnapshot;
use App\Models\WeatherWarning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Weather & Site Risk report. One builder feeds the on-screen preview, print, PDF and CSV, so they can never disagree. Every number says
 * where it came from: "Observed on site" (a person), or a provider forecast with its provider name and the time it was fetched.
 */
class WeatherReportBuilder
{
    public const ROW_LIMIT = 2000;

    private const KINDS = ['forecast_rule' => 'Forecast rule', 'provider_alert' => 'Provider alert'];

    private const STATUS = ['open' => 'Open', 'acknowledged' => 'Acknowledged', 'expired' => 'Expired (forecast changed or window passed)', 'dismissed' => 'Marked not applicable'];

    public function build(User $user, array $f): array
    {
        $accountId = (int) $user->account_id;
        $from = CarbonImmutable::parse($f['from'])->startOfDay();
        $to = CarbonImmutable::parse($f['to'])->endOfDay();
        $projects = WeatherAccess::projects($user, false)->whereNull('warehouses.deleted_at')->when($f['project'], fn ($q, $v) => $q->where('warehouses.id', $v))->orderBy('warehouses.name')->get(['warehouses.id', 'warehouses.name', 'warehouses.code', 'warehouses.latitude', 'warehouses.longitude', 'warehouses.account_id']);
        $ids = $projects->pluck('id')->all() ?: [0];
        $names = $projects->pluck('name', 'id');
        $opt = WeatherSettings::options();

        $obs = WeatherObservation::query()->where('account_id', $accountId)->whereIn('warehouse_id', $ids)->whereBetween('observed_at', [$from, $to])->with('recorder:id,name')->orderBy('observed_at')->get();
        $warnings = WeatherWarning::query()->where('account_id', $accountId)->whereIn('warehouse_id', $ids)->whereBetween('raised_at', [$from, $to])->with(['acknowledger:id,name', 'workPackage:id,reference,title'])->orderBy('raised_at')->get();
        $fmt = fn ($t) => $t ? CarbonImmutable::parse($t)->format('d M Y H:i') : '';

        // ---- observations
        $obsCols = [['key' => 'when', 'label' => 'Observed at', 'align' => 'left'], ['key' => 'project', 'label' => 'Project', 'align' => 'left'], ['key' => 'source', 'label' => 'Source', 'align' => 'left'],
            ['key' => 'conditions', 'label' => 'Conditions', 'align' => 'left'], ['key' => 'rain', 'label' => 'Rain', 'align' => 'left'], ['key' => 'temp', 'label' => 'Temp (°C)', 'align' => 'right'],
            ['key' => 'wind', 'label' => 'Wind', 'align' => 'left'], ['key' => 'by', 'label' => 'Recorded by', 'align' => 'left'], ['key' => 'notes', 'label' => 'Notes', 'align' => 'left']];
        $obsRows = $obs->map(fn (WeatherObservation $o) => [
            'when' => $fmt($o->observed_at), 'project' => $names[$o->warehouse_id] ?? '', 'source' => 'Observed on site', 'conditions' => $opt['conditions'][$o->conditions] ?? $o->conditions,
            'rain' => trim(($o->rainfall_level ? ($opt['rainfall'][$o->rainfall_level] ?? $o->rainfall_level) : '') . ($o->rainfall_mm !== null ? ' ' . rtrim(rtrim(number_format($o->rainfall_mm, 1), '0'), '.') . ' mm' : '')),
            'temp' => $o->temperature_c !== null ? number_format($o->temperature_c, 1) : '', 'wind' => trim(($o->wind_level ? ($opt['wind'][$o->wind_level] ?? $o->wind_level) : '') . ($o->wind_kmh !== null ? ' ' . rtrim(rtrim(number_format($o->wind_kmh, 1), '0'), '.') . ' km/h' : '')),
            'by' => optional($o->recorder)->name ?? '', 'notes' => (string) $o->notes,
        ])->all();

        // ---- warnings and acknowledgements
        $snapIds = $warnings->pluck('snapshot_id')->filter()->unique()->values();
        $snaps = WeatherSnapshot::query()->whereIn('id', $snapIds)->get()->keyBy('id');
        $warnCols = [['key' => 'raised', 'label' => 'Raised', 'align' => 'left'], ['key' => 'project', 'label' => 'Project', 'align' => 'left'], ['key' => 'kind', 'label' => 'Kind', 'align' => 'left'], ['key' => 'level', 'label' => 'Level', 'align' => 'left'],
            ['key' => 'title', 'label' => 'Warning', 'align' => 'left'], ['key' => 'window', 'label' => 'Window', 'align' => 'left'], ['key' => 'work', 'label' => 'Scheduled work', 'align' => 'left'], ['key' => 'status', 'label' => 'Status', 'align' => 'left'],
            ['key' => 'ack_by', 'label' => 'Acknowledged by', 'align' => 'left'], ['key' => 'ack_at', 'label' => 'Acknowledged at', 'align' => 'left'], ['key' => 'decision', 'label' => 'Decision', 'align' => 'left'], ['key' => 'note', 'label' => 'Note', 'align' => 'left'],
            ['key' => 'source', 'label' => 'Source (forecast fetched)', 'align' => 'left']];
        $warnRows = $warnings->map(fn (WeatherWarning $x) => [
            'raised' => $fmt($x->raised_at), 'project' => $names[$x->warehouse_id] ?? '', 'kind' => self::KINDS[$x->kind] ?? $x->kind, 'level' => ucfirst($x->severity), 'title' => $x->title . ': ' . $x->message,
            'window' => trim($fmt($x->window_start) . ($x->window_end ? ' to ' . $fmt($x->window_end) : '')), 'work' => $x->workPackage ? $x->workPackage->reference . ' ' . $x->workPackage->title : ($x->details['work_package']['title'] ?? ''),
            'status' => self::STATUS[$x->status] ?? $x->status, 'ack_by' => optional($x->acknowledger)->name ?? '', 'ack_at' => $fmt($x->acknowledged_at), 'decision' => (string) $x->acknowledge_decision, 'note' => (string) $x->acknowledge_note,
            'source' => ($x->details['forecast_provider'] ?? $x->source_provider ?? '') . (isset($snaps[$x->snapshot_id]) ? ', ' . $snaps[$x->snapshot_id]->fetched_at->format('d M Y H:i') : ''),
        ])->all();

        // ---- forecast against observed, one row per project and day that has observations
        [$cmpRows, $usedSnapshots] = $this->comparison($obs, $projects, $accountId, $opt);
        $cmpCols = [['key' => 'date', 'label' => 'Date', 'align' => 'left'], ['key' => 'project', 'label' => 'Project', 'align' => 'left'], ['key' => 'observed', 'label' => 'Observed on site', 'align' => 'left'],
            ['key' => 'forecast', 'label' => 'Forecast for the day', 'align' => 'left'], ['key' => 'source', 'label' => 'Forecast source and time', 'align' => 'left']];

        // ---- sources: every forecast snapshot used above, plus the provider calls that failed in the period
        $srcCols = [['key' => 'fetched', 'label' => 'Fetched at', 'align' => 'left'], ['key' => 'provider', 'label' => 'Provider', 'align' => 'left'], ['key' => 'kind', 'label' => 'Data', 'align' => 'left'], ['key' => 'place', 'label' => 'Place (rounded coordinates)', 'align' => 'left'],
            ['key' => 'trigger', 'label' => 'How', 'align' => 'left'], ['key' => 'status', 'label' => 'Result', 'align' => 'left'], ['key' => 'used', 'label' => 'Used for', 'align' => 'left']];
        $keys = $projects->map(fn ($p) => WeatherManager::coordinates($p)['key'] ?? null)->filter()->unique()->values()->all();
        $failed = $keys ? WeatherSnapshot::query()->where('account_id', $accountId)->whereIn('location_key', $keys)->where('status', 'failed')->whereBetween('fetched_at', [$from, $to])->orderBy('fetched_at')->get() : collect();
        $used = collect($usedSnapshots)->map(fn ($x) => ['snap' => $x['snap'], 'used' => $x['used']]);
        foreach ($snaps as $id => $sn) {
            if (! $used->contains(fn ($u) => $u['snap']->id === $sn->id)) {
                $used->push(['snap' => $sn, 'used' => 'Warning']);
            }
        }
        $srcRows = $used->sortBy(fn ($u) => $u['snap']->fetched_at)->map(fn ($u) => [
            'fetched' => $u['snap']->fetched_at->format('d M Y H:i'), 'provider' => $this->providerLabel($u['snap']->provider), 'kind' => ucfirst($u['snap']->kind), 'place' => $u['snap']->location_key, 'trigger' => $this->triggerLabel($u['snap']->trigger),
            'status' => 'Saved', 'used' => $u['used'],
        ])->values()->all();
        foreach ($failed as $fl) {
            $srcRows[] = ['fetched' => $fl->fetched_at->format('d M Y H:i'), 'provider' => $this->providerLabel($fl->provider), 'kind' => 'Forecast', 'place' => $fl->location_key, 'trigger' => $this->triggerLabel($fl->trigger), 'status' => 'Failed: ' . $fl->error, 'used' => 'Not used (provider unavailable)'];
        }

        $byStatus = $warnings->countBy('status');
        $title = 'Weather & Site Risk Report';
        $projectLabel = $f['project'] ? ($projects->first()->name ?? '-') : 'All projects I can see (' . $projects->count() . ')';
        $trunc = fn (array $rows) => array_slice($rows, 0, self::ROW_LIMIT);

        return [
            'type' => 'weather_site_risk', 'title' => $title, 'subtitle' => 'Observations recorded on site, advisory forecast warnings and who acknowledged them, forecast against what was observed, and the source and time of every forecast used.',
            'reference' => 'WX-' . now()->format('Ymd') . '-' . ($f['project'] ?: 'ALL') . '-' . $user->id,
            'filters' => ['Project' => $projectLabel, 'Period' => $from->format('d M Y') . ' to ' . $to->format('d M Y')],
            'period' => ['label' => $from->format('d M Y') . ' to ' . $to->format('d M Y'), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'tiles' => [
                ['label' => 'Observations on site', 'value' => count($obsRows)], ['label' => 'Warnings raised', 'value' => $warnings->count()], ['label' => 'Still open', 'value' => (int) ($byStatus['open'] ?? 0)],
                ['label' => 'Acknowledged', 'value' => (int) ($byStatus['acknowledged'] ?? 0)], ['label' => 'Provider alerts', 'value' => $warnings->where('kind', 'provider_alert')->count()],
                ['label' => 'Days compared', 'value' => count($cmpRows)], ['label' => 'Failed provider calls', 'value' => $failed->count()],
            ],
            'table_title' => 'Weather observed on site', 'columns' => $obsCols, 'rows' => $trunc($obsRows), 'total_rows' => count($obsRows), 'truncated' => count($obsRows) > self::ROW_LIMIT,
            'sections' => [
                ['type' => 'table', 'title' => 'Forecast warnings and acknowledgements', 'columns' => $warnCols, 'rows' => $trunc($warnRows), 'empty' => 'No weather warnings were raised in this period.', 'placement' => 'after'],
                ['type' => 'table', 'title' => 'Forecast against observed', 'columns' => $cmpCols, 'rows' => $trunc($cmpRows), 'empty' => 'No day in this period has both a site observation and a saved forecast.', 'placement' => 'after'],
                ['type' => 'table', 'title' => 'Forecast sources', 'columns' => $srcCols, 'rows' => $trunc($srcRows), 'empty' => 'No forecast was saved for this period.', 'placement' => 'after'],
            ],
            'datasets' => [
                'observations' => ['columns' => $obsCols, 'rows' => $obsRows], 'warnings' => ['columns' => $warnCols, 'rows' => $warnRows],
                'comparison' => ['columns' => $cmpCols, 'rows' => $cmpRows], 'sources' => ['columns' => $srcCols, 'rows' => $srcRows],
            ],
            'notes' => [
                'OBSERVED means a person recorded it on site. FORECAST means a weather provider predicted it; the provider and the time it was fetched are shown with each forecast.',
                'Warnings are advisory. They say forecast conditions may be unsuitable for an activity and that somebody should review. They never approve, forbid or certify any work, and acknowledging one changes no work package.',
                'The thresholds behind the warnings are starting values until a person has reviewed them against the applicable engineering and safety requirements. A warning marked "starting values" was raised from an unreviewed threshold.',
                'Forecast uncertainty is shown only where the provider supplies it. Forecasts are less certain further ahead.',
            ],
            'qr_url' => route('weather.reports.index', array_filter(['project' => $f['project'] ?: null, 'from' => $from->toDateString(), 'to' => $to->toDateString()])),
        ];
    }

    private function providerLabel(?string $key): string
    {
        return (string) (config('weather.providers.' . $key . '.label') ?: ucfirst(str_replace('_', ' ', (string) $key)));
    }

    /** How a forecast came to be saved, in words a reader recognises. */
    private function triggerLabel(?string $trigger): string
    {
        return match ($trigger) {
            'view' => 'Opened on the Weather page',
            'manual' => 'Refresh button',
            'schedule', 'scheduled' => 'Scheduled update',
            'location' => 'Location saved',
            default => ucfirst((string) $trigger),
        };
    }

    /** @return array{0: list<array>, 1: list<array{snap: WeatherSnapshot, used: string}>} */
    private function comparison(Collection $obs, Collection $projects, int $accountId, array $opt): array
    {
        $rows = [];
        $used = [];
        foreach ($obs->groupBy(fn ($o) => $o->warehouse_id . '|' . $o->observed_at->toDateString()) as $key => $group) {
            [$pid, $date] = explode('|', $key);
            $project = $projects->firstWhere('id', (int) $pid);
            $loc = $project ? WeatherManager::coordinates($project) : null;
            $observed = $this->observedSummary($group, $opt);
            $forecast = '';
            $source = 'No forecast was saved for this place';
            if ($loc) {
                $day = CarbonImmutable::parse($date);
                $base = WeatherSnapshot::query()->where('account_id', $accountId)->where('location_key', $loc['key'])->where('kind', 'forecast')->where('status', 'ok');
                $snap = (clone $base)->where('fetched_at', '<=', $day->startOfDay())->orderByDesc('fetched_at')->first();
                $when = 'issued before the day';
                if (! $snap) {
                    $snap = (clone $base)->whereBetween('fetched_at', [$day->startOfDay(), $day->endOfDay()])->orderBy('fetched_at')->first();
                    $when = 'issued during the day';
                }
                if ($snap) {
                    $forecast = $this->forecastSummary($snap->payload, $date);
                    if ($forecast !== '') {
                        $source = $snap->provider . ', fetched ' . $snap->fetched_at->format('d M Y H:i') . ' (' . $when . ')';
                        $used[] = ['snap' => $snap, 'used' => 'Comparison for ' . $day->format('d M')];
                    } else {
                        $source = $snap->provider . ', fetched ' . $snap->fetched_at->format('d M Y H:i') . ', but it does not cover this day';
                    }
                }
            } else {
                $source = 'The project has no location';
            }
            $rows[] = ['date' => CarbonImmutable::parse($date)->format('d M Y'), 'project' => $project->name ?? '', 'observed' => $observed, 'forecast' => $forecast !== '' ? $forecast : 'Not available', 'source' => $source];
        }

        return [$rows, $used];
    }

    private function observedSummary(Collection $group, array $opt): string
    {
        $parts = [$group->pluck('conditions')->unique()->map(fn ($c) => $opt['conditions'][$c] ?? $c)->implode(', ')];
        $mm = $group->pluck('rainfall_mm')->filter(fn ($v) => $v !== null);
        if ($mm->isNotEmpty()) {
            $parts[] = 'rain ' . rtrim(rtrim(number_format($mm->sum(), 1), '0'), '.') . ' mm';
        } elseif ($lv = $group->pluck('rainfall_level')->filter()->unique()->map(fn ($r) => mb_strtolower($opt['rainfall'][$r] ?? $r, 'UTF-8'))->implode(', ')) {
            $parts[] = $lv;
        }
        $t = $group->pluck('temperature_c')->filter(fn ($v) => $v !== null);
        if ($t->isNotEmpty()) {
            $parts[] = (round($t->min()) == round($t->max()) ? number_format($t->max(), 0) : number_format($t->min(), 0) . ' to ' . number_format($t->max(), 0)) . ' °C';
        }
        $w = $group->pluck('wind_kmh')->filter(fn ($v) => $v !== null);
        if ($w->isNotEmpty()) {
            $parts[] = 'wind up to ' . rtrim(rtrim(number_format($w->max(), 1), '0'), '.') . ' km/h';
        } elseif ($wl = $group->pluck('wind_level')->filter()->unique()->map(fn ($r) => mb_strtolower($opt['wind'][$r] ?? $r, 'UTF-8'))->implode(', ')) {
            $parts[] = $wl;
        }

        return implode('; ', array_filter($parts));
    }

    /** One line for one day from a saved forecast: the provider's daily row if it has one, otherwise worked out from its hours. */
    public static function forecastSummary(?array $payload, string $date): string
    {
        if (! $payload) {
            return '';
        }
        $hours = array_values(array_filter($payload['hourly'] ?? [], fn ($h) => str_starts_with($h['time'], $date)));
        $day = collect($payload['daily'] ?? [])->firstWhere('date', $date);
        if (! $hours && ! $day) {
            return '';
        }
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
        $probs = array_filter(array_column($hours, 'precip_probability_pct'), fn ($v) => $v !== null);
        $parts = [];
        $prob = $probs ? max($probs) : ($day['precip_probability_pct'] ?? null);
        if ($prob !== null) {
            $parts[] = 'rain probability up to ' . (int) $prob . '%';
        }
        $mm = $hours ? array_sum(array_map(fn ($h) => (float) ($h['precip_mm'] ?? 0), $hours)) : ($day['precip_mm'] ?? null);
        if ($mm !== null) {
            $parts[] = $num($mm) . ' mm of rain' . ($hours && count($hours) < 24 ? ' (hours covered: ' . count($hours) . ')' : '');
        }
        $wind = $hours ? max(array_map(fn ($h) => (float) ($h['wind_kmh'] ?? 0), $hours)) : ($day['wind_kmh'] ?? null);
        if ($wind) {
            $parts[] = 'wind up to ' . $num($wind) . ' km/h';
        }
        $gust = $hours ? max(array_map(fn ($h) => (float) ($h['gust_kmh'] ?? 0), $hours)) : ($day['gust_kmh'] ?? null);
        if ($gust) {
            $parts[] = 'gusts up to ' . $num($gust) . ' km/h';
        }
        $tmax = $day['temp_max_c'] ?? ($hours ? max(array_map(fn ($h) => (float) ($h['temperature_c'] ?? 0), $hours)) : null);
        $tmin = $day['temp_min_c'] ?? ($hours ? min(array_map(fn ($h) => (float) ($h['temperature_c'] ?? 0), $hours)) : null);
        if ($tmax !== null && $tmin !== null) {
            $parts[] = $num($tmin) . ' to ' . $num($tmax) . ' °C';
        }

        return implode('; ', $parts);
    }
}
