<?php

namespace App\Services\Prayer;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Prayer times from the sun's position (the same astronomical method the well-known PrayTimes.org library uses), for any place and
 * any day, with no outside service: Fajr, sunrise, Dhuhr, Asr, Maghrib and Isha as local times in the place's own time zone.
 */
class PrayerTimes
{
    /** name => [Fajr angle, Isha: angle or "N min" after Maghrib] */
    public const METHODS = [
        'MWL' => ['label' => 'Muslim World League', 'fajr' => 18, 'isha' => 17],
        'ISNA' => ['label' => 'Islamic Society of North America', 'fajr' => 15, 'isha' => 15],
        'Egypt' => ['label' => 'Egyptian General Authority of Survey', 'fajr' => 19.5, 'isha' => 17.5],
        'Makkah' => ['label' => 'Umm al-Qura, Makkah', 'fajr' => 18.5, 'isha' => '90 min'],
        'Karachi' => ['label' => 'University of Islamic Sciences, Karachi', 'fajr' => 18, 'isha' => 18],
        'Tehran' => ['label' => 'Institute of Geophysics, Tehran', 'fajr' => 17.7, 'isha' => 14],
    ];

    private const MAGHRIB_ANGLE = 0.0; // sunset

    public function __construct(
        private float $lat,
        private float $lng,
        private string $timezone = 'Africa/Dar_es_Salaam',
        private string $method = 'MWL',
        private int $asrFactor = 1,
        private float $elevation = 0,
    ) {
    }

    /** @return array<string, CarbonImmutable> fajr, sunrise, dhuhr, asr, maghrib, isha (before any manual adjustment) */
    public function forDate(CarbonImmutable|Carbon $date): array
    {
        $day = CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, $this->timezone);
        $offset = $day->utcOffset() / 60; // hours from UTC on that day
        $jd = $this->julian($day->year, $day->month, $day->day) - $this->lng / (15 * 24);

        $t = ['fajr' => 5, 'sunrise' => 6, 'dhuhr' => 12, 'asr' => 13, 'maghrib' => 18, 'isha' => 18];
        $times = $this->compute($t, $jd);
        $times = $this->compute($times, $jd); // second pass with the first answer, for accuracy
        $m = self::METHODS[$this->method] ?? self::METHODS['MWL'];

        $out = [];
        foreach ($times as $name => $h) {
            $h = $h + $offset - $this->lng / 15;
            if ($name === 'isha' && is_string($m['isha'])) {
                $h = $times['maghrib'] + $offset - $this->lng / 15 + ((float) $m['isha']) / 60;
            }
            $out[$name] = $day->addSeconds((int) round($this->fix($h, 24) * 3600));
        }

        return $out;
    }

    /** @param array<string,float> $t hours of the day */
    private function compute(array $t, float $jd): array
    {
        $m = self::METHODS[$this->method] ?? self::METHODS['MWL'];
        $r = $this->sunPosition($jd + $t['sunrise'] / 24);
        $riseSet = 0.833 + 0.0347 * sqrt(max(0, $this->elevation));

        return [
            'fajr' => $this->angleTime($jd, $t['fajr'], $m['fajr'], true),
            'sunrise' => $this->angleTime($jd, $t['sunrise'], $riseSet, true),
            'dhuhr' => $this->midDay($jd, $t['dhuhr']),
            'asr' => $this->asrTime($jd, $t['asr']),
            'maghrib' => $this->angleTime($jd, $t['maghrib'], $riseSet, false),
            'isha' => is_string($m['isha']) ? $this->angleTime($jd, $t['maghrib'], $riseSet, false) : $this->angleTime($jd, $t['isha'], $m['isha'], false),
        ];
    }

    private function midDay(float $jd, float $t): float
    {
        $eq = $this->sunPosition($jd + $t / 24)[1];

        return $this->fix(12 - $eq, 24);
    }

    private function angleTime(float $jd, float $t, float $angle, bool $ccw): float
    {
        $decl = $this->sunPosition($jd + $t / 24)[0];
        $noon = $this->midDay($jd, $t);
        $x = (-$this->sin($angle) - $this->sin($decl) * $this->sin($this->lat)) / ($this->cos($decl) * $this->cos($this->lat));
        $x = max(-1, min(1, $x));
        $delta = $this->arccos($x) / 15;

        return $noon + ($ccw ? -$delta : $delta);
    }

    private function asrTime(float $jd, float $t): float
    {
        $decl = $this->sunPosition($jd + $t / 24)[0];
        $angle = -$this->arccot($this->asrFactor + $this->tan(abs($this->lat - $decl)));

        return $this->angleTime($jd, $t, $angle, false);
    }

    /** @return array{0: float, 1: float} declination (degrees), equation of time (hours) */
    private function sunPosition(float $jd): array
    {
        $d = $jd - 2451545.0;
        $g = $this->fix(357.529 + 0.98560028 * $d, 360);
        $q = $this->fix(280.459 + 0.98564736 * $d, 360);
        $L = $this->fix($q + 1.915 * $this->sin($g) + 0.020 * $this->sin(2 * $g), 360);
        $e = 23.439 - 0.00000036 * $d;
        $ra = $this->arctan2($this->cos($e) * $this->sin($L), $this->cos($L)) / 15;
        $eqt = $q / 15 - $this->fix($ra, 24);
        $decl = $this->arcsin($this->sin($e) * $this->sin($L));

        return [$decl, $eqt];
    }

    private function julian(int $y, int $m, int $d): float
    {
        if ($m <= 2) {
            $y -= 1;
            $m += 12;
        }
        $a = floor($y / 100);
        $b = 2 - $a + floor($a / 4);

        return floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $b - 1524.5;
    }

    private function fix(float $a, float $mode): float
    {
        $a = $a - $mode * floor($a / $mode);

        return $a < 0 ? $a + $mode : $a;
    }

    private function sin(float $d): float { return sin(deg2rad($d)); }
    private function cos(float $d): float { return cos(deg2rad($d)); }
    private function tan(float $d): float { return tan(deg2rad($d)); }
    private function arcsin(float $x): float { return rad2deg(asin($x)); }
    private function arccos(float $x): float { return rad2deg(acos($x)); }
    private function arctan2(float $y, float $x): float { return rad2deg(atan2($y, $x)); }
    private function arccot(float $x): float { return rad2deg(atan(1 / $x)); }
}
