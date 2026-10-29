<?php

namespace App\Services\HolidayGreetings;

use App\Models\HolidayDay;
use Carbon\Carbon;

/**
 * The public holidays of Tanzania for a year: the fixed dates, Easter (worked out), and the Islamic holidays (worked out from the tabular Islamic calendar, which can differ
 * by a day or two from the moon sighting, so they are saved as "date to confirm" until a Super Admin confirms them). Rows are only ever added: a holiday that was edited, switched off
 * or removed is never brought back or overwritten.
 */
class HolidayCalendar
{
    /** @return array<int,array{key:string,name:string,date:Carbon,kind:string,theme:string,confirmed:bool,enabled:bool}> */
    public static function definitions(int $year): array
    {
        $d = fn (int $m, int $day) => Carbon::create($year, $m, $day, 0, 0, 0, config('app.timezone'))->startOfDay();
        $easter = self::easter($year);
        $rows = [
            ['new_year', "New Year's Day", $d(1, 1), 'public', 'newyear'],
            ['zanzibar_revolution', 'Zanzibar Revolution Day', $d(1, 12), 'public', 'revolution'],
            ['karume_day', 'Karume Day', $d(4, 7), 'public', 'karume'],
            ['good_friday', 'Good Friday', $easter->copy()->subDays(2), 'public', 'goodfriday'],
            ['easter_monday', 'Easter Monday', $easter->copy()->addDay(), 'public', 'easter'],
            ['union_day', 'Union Day', $d(4, 26), 'public', 'union'],
            ['workers_day', "Workers' Day", $d(5, 1), 'public', 'workers'],
            ['saba_saba', 'Saba Saba (Industry Day)', $d(7, 7), 'observance', 'sabasaba'],
            ['nane_nane', "Nane Nane (Farmers' Day)", $d(8, 8), 'observance', 'farmers'],
            ['nyerere_day', 'Nyerere Day', $d(10, 14), 'public', 'nyerere'],
            ['independence_day', 'Independence Day', $d(12, 9), 'public', 'independence'],
            ['christmas', 'Christmas Day', $d(12, 25), 'public', 'christmas'],
            ['boxing_day', 'Boxing Day', $d(12, 26), 'public', 'boxing'],
        ];
        $out = [];
        foreach ($rows as [$key, $name, $date, $kind, $theme]) {
            $out[] = ['key' => $key, 'name' => $name, 'date' => $date, 'kind' => $kind, 'theme' => $theme, 'confirmed' => true, 'enabled' => $kind !== 'observance'];
        }
        foreach ([['eid_fitr', 'Eid al-Fitr', 10, 1, 'eidfitr'], ['eid_adha', 'Eid al-Adha (Idd el Hajj)', 12, 10, 'eidadha'], ['maulid', 'Maulid (Prophet\'s Birthday)', 3, 12, 'maulid']] as [$key, $name, $im, $id, $theme]) {
            foreach (self::islamicInGregorianYear($year, $im, $id) as $date) {
                $out[] = ['key' => $key, 'name' => $name, 'date' => $date, 'kind' => 'religious', 'theme' => $theme, 'confirmed' => false, 'enabled' => true];
            }
        }
        usort($out, fn ($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);

        return $out;
    }

    /** Adds the holidays of a year that are not on the list yet (including the ones somebody removed). Returns how many were added. */
    public static function ensureYear(int $accountId, int $year): int
    {
        $existing = HolidayDay::withoutGlobalScope('account')->withTrashed()->where('account_id', $accountId)->where('year', $year)->pluck('key')->all();
        $added = 0;
        foreach (self::definitions($year) as $h) {
            if (in_array($h['key'], $existing, true)) {
                continue;
            }
            HolidayDay::withoutGlobalScope('account')->create([
                'account_id' => $accountId, 'year' => $year, 'key' => $h['key'], 'name' => $h['name'], 'theme' => $h['theme'], 'kind' => $h['kind'],
                'date' => $h['date']->format('Y-m-d'), 'date_confirmed' => $h['confirmed'], 'is_enabled' => $h['enabled'],
            ]);
            $added++;
        }

        return $added;
    }

    /** Western Easter Sunday (the anonymous Gregorian algorithm). */
    public static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day, 0, 0, 0, config('app.timezone'))->startOfDay();
    }

    /** The Gregorian dates in $year on which the Islamic date (month, day) falls (tabular calendar). */
    public static function islamicInGregorianYear(int $year, int $month, int $day): array
    {
        $found = [];
        $approx = (int) floor(($year - 622) * 33 / 32);
        foreach ([$approx - 1, $approx, $approx + 1, $approx + 2] as $hy) {
            $jd = $day + (int) ceil(29.5 * ($month - 1)) + ($hy - 1) * 354 + (int) floor((3 + 11 * $hy) / 30) + 1948439.5 - 1;
            $date = Carbon::create(1970, 1, 1, 0, 0, 0, config('app.timezone'))->addDays((int) floor($jd - 2440587.5 + 0.5));
            if ((int) $date->format('Y') === $year) {
                $found[$date->format('Y-m-d')] = $date;
            }
        }

        return array_values($found);
    }
}
