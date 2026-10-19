<?php

namespace App\Services\Subcontracts;

/** Money arithmetic for the schedule: one rounding rule, in one place. */
class SubcontractAmounts
{
    /** The amount of a work item: quantity x rate (4 and 4 places in, 2 places out, rounded half up) or the typed fixed / lump sum amount. */
    public static function itemAmount(string $method, $quantity, $rate, $typed): string
    {
        if ($method === 'qty_rate') {
            $raw = bcmul(self::scale4($quantity), self::scale4($rate), 8);

            return self::round2($raw);
        }

        return self::money($typed);
    }

    /** A typed money amount rounded half up to 2 places (no exponent notation: "1e3" is not an amount). */
    public static function money($v): string
    {
        return self::round2(self::clean($v, 6));
    }

    /** A quantity or a rate as it is stored (4 places, rounded half up; the amount is calculated from this stored value, so quantity x rate always equals the amount). */
    public static function scale4($v): string
    {
        $c = self::clean($v, 8);
        $sign = bccomp($c, '0', 8) < 0 ? '-' : '';

        return $sign . bcadd(bcadd(ltrim($c, '-'), '0.00005', 8), '0', 4);
    }

    public static function clean($v, int $scale = 2): string
    {
        $s = trim(str_replace([',', ' '], '', (string) $v));
        if ($s === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return bcadd('0', '0', $scale);
        }

        return bcadd($s, '0', $scale);
    }

    public static function round2(string $v): string
    {
        $sign = bccomp($v, '0', 8) < 0 ? '-' : '';
        $abs = ltrim($v, '-');

        return $sign . bcadd(bcadd($abs, '0.005', 8), '0', 2);
    }

    /** 1,234,567.89 from a decimal string without passing through a float (exact to the cent whatever the size; rounded half up). */
    public static function group($v): string
    {
        $s = trim((string) $v);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return number_format((float) $v, 2, '.', ',');
        }
        $r = self::round2(bcadd($s, '0', 8));
        $neg = str_starts_with($r, '-') && $r !== '-0.00' ? '-' : '';
        [$int, $frac] = explode('.', ltrim($r, '-'));

        return $neg . preg_replace('/\B(?=(\d{3})+$)/', ',', $int) . '.' . $frac;
    }

    public static function fmt($v, string $currency = ''): string
    {
        return self::group($v) . ($currency ? ' ' . $currency : '');
    }
}
