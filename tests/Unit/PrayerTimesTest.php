<?php

namespace Tests\Unit;

use App\Services\Prayer\PrayerTimes;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PrayerTimesTest extends TestCase
{
    public function test_dar_es_salaam_times_are_in_the_expected_range(): void
    {
        $t = (new PrayerTimes(-6.7924, 39.2083, 'Africa/Dar_es_Salaam', 'MWL', 1))->forDate(CarbonImmutable::parse('2026-10-10'));

        $this->assertSame(['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'], array_keys($t));
        $this->assertSame(4, $t['fajr']->hour);
        $this->assertSame(6, $t['sunrise']->hour);
        $this->assertSame(12, $t['dhuhr']->hour);
        $this->assertSame(15, $t['asr']->hour);
        $this->assertSame(18, $t['maghrib']->hour);
        $this->assertSame(19, $t['isha']->hour);
        $this->assertTrue($t['fajr'] < $t['sunrise'] && $t['sunrise'] < $t['dhuhr'] && $t['dhuhr'] < $t['asr'] && $t['asr'] < $t['maghrib'] && $t['maghrib'] < $t['isha']);
        $this->assertSame('+03:00', $t['dhuhr']->format('P'));
    }

    public function test_hanafi_asr_is_later_than_standard(): void
    {
        $std = (new PrayerTimes(-6.7924, 39.2083, 'Africa/Dar_es_Salaam', 'MWL', 1))->forDate(CarbonImmutable::parse('2026-10-10'));
        $han = (new PrayerTimes(-6.7924, 39.2083, 'Africa/Dar_es_Salaam', 'MWL', 2))->forDate(CarbonImmutable::parse('2026-10-10'));
        $this->assertTrue($han['asr'] > $std['asr']);
    }
}
