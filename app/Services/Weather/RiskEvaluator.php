<?php

namespace App\Services\Weather;

use App\Models\WeatherRiskRule;
use Carbon\CarbonImmutable;

/**
 * Pure comparison of a forecast with one activity's thresholds. It decides nothing about work: it only says "the forecast goes past this
 * number in this window". The wording is deliberately advisory ("may be unsuitable", "review before proceeding").
 */
class RiskEvaluator
{
    /** metric => [rule column, label used in sentences, unit, direction] */
    public const METRICS = [
        'wind' => ['max_wind_kmh', 'wind', 'km/h', 'above'],
        'gust' => ['max_gust_kmh', 'wind gusts', 'km/h', 'above'],
        'rain_probability' => ['max_rain_probability_pct', 'rain probability', '%', 'above'],
        'rain_hour' => ['max_precip_mm_per_hour', 'rainfall', 'mm in an hour', 'above'],
        'rain_day' => ['max_precip_mm_per_day', 'rainfall', 'mm in a day', 'above'],
        'temp_low' => ['min_temp_c', 'temperature', '°C', 'below'],
        'temp_high' => ['max_temp_c', 'temperature', '°C', 'above'],
    ];

    /**
     * @param array $hourly normalised forecast hours (see WeatherProvider)
     * @return array{rule_id:int, activity_key:string, label:string, severity:string, level:string, breaches:list<array>, window_start:?string, window_end:?string, score:float, lookahead_hours:int, hours_checked:int, lead_hours:?int, reviewed:bool}
     */
    public static function assess(WeatherRiskRule $rule, array $hourly, CarbonImmutable $now): array
    {
        $from = $now->startOfHour();
        $to = $from->addHours(max(1, (int) $rule->lookahead_hours));
        $hours = array_values(array_filter($hourly, function ($h) use ($from, $to) {
            $t = CarbonImmutable::parse($h['time']);

            return $t->gte($from) && $t->lt($to);
        }));

        $breaches = [];
        foreach (self::METRICS as $metric => [$col, $label, $unit, $dir]) {
            $threshold = $rule->{$col};
            if ($threshold === null) {
                continue;
            }
            $b = $metric === 'rain_day' ? self::dailyBreach($hours, (float) $threshold) : self::hourlyBreach($metric, $hours, (float) $threshold, $dir);
            if ($b) {
                $breaches[] = $b + ['metric' => $metric, 'label' => $label, 'unit' => $unit, 'threshold' => (float) $threshold, 'direction' => $dir];
            }
        }

        $starts = array_filter(array_column($breaches, 'from'));
        $ends = array_filter(array_column($breaches, 'to'));
        $firstHour = $hours ? $hours[0]['time'] : null;

        return [
            'rule_id' => (int) $rule->id, 'activity_key' => $rule->activity_key, 'label' => $rule->label, 'severity' => $rule->severity,
            'level' => $breaches ? $rule->severity : 'clear', 'breaches' => $breaches,
            'window_start' => $starts ? min($starts) : null, 'window_end' => $ends ? max($ends) : null,
            'score' => $breaches ? round(max(array_column($breaches, 'score')), 1) : 0.0,
            'lookahead_hours' => (int) $rule->lookahead_hours, 'hours_checked' => count($hours),
            'lead_hours' => $breaches && $starts ? max(0, (int) round($now->diffInMinutes(CarbonImmutable::parse(min($starts)), false) / 60)) : null,
            'reviewed' => $rule->isReviewed(), 'first_hour' => $firstHour,
        ];
    }

    private static function hourlyBreach(string $metric, array $hours, float $threshold, string $dir): ?array
    {
        $field = ['wind' => 'wind_kmh', 'gust' => 'gust_kmh', 'rain_probability' => 'precip_probability_pct', 'rain_hour' => 'precip_mm', 'temp_low' => 'temperature_c', 'temp_high' => 'temperature_c'][$metric];
        $peak = null;
        $peakAt = null;
        $first = null;
        $last = null;
        foreach ($hours as $h) {
            $v = $h[$field] ?? null;
            if ($v === null) {
                continue;
            }
            $over = $dir === 'above' ? $v > $threshold : $v < $threshold;
            if (! $over) {
                continue;
            }
            $first ??= $h['time'];
            $last = $h['time'];
            if ($peak === null || ($dir === 'above' ? $v > $peak : $v < $peak)) {
                $peak = $v;
                $peakAt = $h['time'];
            }
        }
        if ($peak === null) {
            return null;
        }

        return ['value' => (float) $peak, 'at' => $peakAt, 'from' => $first, 'to' => CarbonImmutable::parse($last)->addHour()->format('Y-m-d H:i:s'), 'score' => self::score((float) $peak, $threshold, $dir)];
    }

    private static function dailyBreach(array $hours, float $threshold): ?array
    {
        $sums = [];
        $firstAt = [];
        $lastAt = [];
        foreach ($hours as $h) {
            if (($h['precip_mm'] ?? null) === null) {
                continue;
            }
            $d = substr($h['time'], 0, 10);
            $sums[$d] = ($sums[$d] ?? 0) + (float) $h['precip_mm'];
            $firstAt[$d] ??= $h['time'];
            $lastAt[$d] = $h['time'];
        }
        $bestDay = null;
        foreach ($sums as $d => $s) {
            if ($s > $threshold && ($bestDay === null || $s > $sums[$bestDay])) {
                $bestDay = $d;
            }
        }
        if ($bestDay === null) {
            return null;
        }
        $v = round($sums[$bestDay], 1);

        return ['value' => $v, 'at' => $firstAt[$bestDay], 'from' => $firstAt[$bestDay], 'to' => CarbonImmutable::parse($lastAt[$bestDay])->addHour()->format('Y-m-d H:i:s'), 'score' => self::score($v, $threshold, 'above')];
    }

    /** How far past the threshold, as a percentage of it. Unit free, so different metrics can be compared and re-open decisions made. */
    public static function score(float $value, float $threshold, string $dir): float
    {
        $base = max(abs($threshold), 1.0);

        return round(max(0.0, $dir === 'above' ? ($value - $threshold) / $base * 100 : ($threshold - $value) / $base * 100), 1);
    }

    // ------------------------------------------------------------------------------------------------------------------ wording

    public static function when(?string $time, ?CarbonImmutable $now = null): string
    {
        if (! $time) {
            return '';
        }
        $now ??= CarbonImmutable::now(config('weather.timezone'));
        $t = CarbonImmutable::parse($time);
        if ($t->isSameDay($now)) {
            return $t->format('H:i');
        }
        if ($t->isSameDay($now->addDay())) {
            return 'tomorrow ' . $t->format('H:i');
        }

        return $t->format('D j M H:i');
    }

    public static function phrase(array $b, ?CarbonImmutable $now = null): string
    {
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
        $from = self::when($b['from'], $now);

        return match ($b['metric']) {
            'rain_day' => 'about ' . $num($b['value']) . ' mm of rain expected on ' . CarbonImmutable::parse($b['at'])->format('D j M'),
            'rain_probability' => 'rain probability up to ' . $num($b['value']) . '% from ' . $from,
            'rain_hour' => 'rainfall up to ' . $num($b['value']) . ' mm in an hour from ' . $from,
            'temp_low' => 'temperature down to ' . $num($b['value']) . ' °C from ' . $from,
            'temp_high' => 'temperature up to ' . $num($b['value']) . ' °C from ' . $from,
            'gust' => 'wind gusts up to ' . $num($b['value']) . ' km/h from ' . $from,
            default => 'wind up to ' . $num($b['value']) . ' km/h from ' . $from,
        };
    }

    /** "Forecast conditions may be unsuitable for roofing (wind gusts up to 52 km/h from 14:00). Review before proceeding." */
    public static function sentence(array $assessment, ?CarbonImmutable $now = null): string
    {
        $parts = array_map(fn ($b) => self::phrase($b, $now), $assessment['breaches']);

        return 'Forecast conditions may be unsuitable for ' . mb_strtolower($assessment['label'], 'UTF-8') . ' (' . implode('; ', $parts) . '). Review before proceeding.';
    }

    /** Does a work package belong to the activity? Words are matched inside its title and category (case-insensitive). */
    public static function matches(WeatherRiskRule $rule, string $title, ?string $category): bool
    {
        $hay = mb_strtolower(trim($title . ' ' . $category), 'UTF-8');
        foreach ((array) $rule->keywords as $kw) {
            $kw = mb_strtolower(trim((string) $kw), 'UTF-8');
            if ($kw !== '' && str_contains($hay, $kw)) {
                return true;
            }
        }

        return false;
    }
}
