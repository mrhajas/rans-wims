<?php

namespace App\Support\Accounts;

/**
 * Money arithmetic that never touches floats. Amounts travel as decimal STRINGS with four decimals (the ledger's own precision) and are
 * rounded to a currency's own precision (TZS 2, JPY 0, ...) with half-up rounding, away from zero, wherever a rule says so.
 */
final class Money
{
    public const SCALE = 4;

    /** The largest single amount accepted (the ledger columns are decimal(20,4): 16 whole digits), and the largest balance an account can hold. */
    public const MAX_ENTRY = '999999999999999.9999';

    public const MAX_BALANCE = '9999999999999999.9999';

    /** A clean numeric string with the ledger scale, or throws for anything that is not a plain decimal number. */
    public static function norm($v): string
    {
        $s = trim((string) $v);
        // a comma is only a thousands separator (1,234,567.50); "1,5" or "1.234,56" are ambiguous and refused rather than read as 15 or 1.2345
        if (str_contains($s, ',') && ! preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException('Not a valid amount: ' . $s);
        }
        $s = str_replace([',', ' '], '', $s);
        if ($s === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException('Not a valid amount: ' . $s);
        }

        return bcadd($s, '0', self::SCALE);
    }

    public static function add($a, $b): string
    {
        return bcadd(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function sub($a, $b): string
    {
        return bcsub(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function cmp($a, $b): int
    {
        return bccomp(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function isPositive($a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function neg($a): string
    {
        return bcmul(self::norm($a), '-1', self::SCALE);
    }

    /** a x rate (the rate keeps 8 decimals), rounded to the target currency's precision. */
    public static function convert($amount, $rate, int $precision): string
    {
        $raw = bcmul(self::norm($amount), bcadd((string) $rate, '0', 8), 12);

        return self::round($raw, $precision);
    }

    /** Half-up rounding away from zero to $precision decimals, returned with the ledger scale. */
    public static function round($v, int $precision): string
    {
        $v = (string) $v;
        $half = '0.' . str_repeat('0', $precision) . '5';
        $neg = str_starts_with($v, '-');
        $r = $neg ? bcsub($v, $half, $precision) : bcadd($v, $half, $precision);

        return bcadd($r, '0', self::SCALE);
    }

    /** True when the amount has no more decimals than the currency allows (12.345 does not fit 2 decimals). */
    public static function fitsPrecision($v, int $precision): bool
    {
        $n = self::norm($v);

        return bccomp(bcadd($n, '0', $precision), $n, self::SCALE) === 0;
    }

    public static function format($v, int $precision = 2): string
    {
        $s = trim((string) $v);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return number_format((float) $v, $precision, '.', ','); // not a plain decimal string (a float, null, scientific notation): the old way
        }
        // exact, on the digits: a float would lose cents on very large amounts
        $r = self::round($s, min($precision, self::SCALE));
        $neg = str_starts_with($r, '-');
        [$int, $dec] = explode('.', ltrim($r, '-')) + [1 => ''];
        $dec = str_pad(substr($dec, 0, $precision), $precision, '0');
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);

        return ($neg && trim($int . $dec, '0,') !== '' ? '-' : '') . $int . ($precision > 0 ? '.' . $dec : '');
    }
}
