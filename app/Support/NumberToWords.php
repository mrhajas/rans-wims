<?php

namespace App\Support;

/**
 * Spells out a currency amount for printed financial documents (Payment
 * Vouchers etc.) — e.g. 2,900,000 -> "TWO MILLION NINE HUNDRED THOUSAND".
 * English only; no external package pulled in for this.
 */
class NumberToWords
{
    private static array $ones = [
        '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE',
        'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN',
    ];

    private static array $tens = [
        '', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY',
    ];

    private static array $scales = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];

    public static function convert(float $amount, string $currencyLabel = 'SHILLINGS', string $currencySubunitLabel = 'CENTS'): string
    {
        // Work in whole cents so 0.995 or 1.999 can never come out as "ONE HUNDRED CENTS": the amount is rounded first, then split.
        $totalCents = (int) round(abs(round($amount, 2)) * 100);
        $whole = intdiv($totalCents, 100);
        $cents = $totalCents % 100;

        $words = $whole === 0 ? 'ZERO' : self::convertInteger($whole);
        $result = trim($words) . ' ' . self::unit($whole, $currencyLabel);

        if ($cents > 0) {
            $result .= ' AND ' . self::convertInteger($cents) . ' ' . self::unit($cents, $currencySubunitLabel);
        }

        return $result . ' ONLY';
    }

    /** "SHILLINGS" -> "SHILLING" when the count is exactly one ("ONE SHILLING", "ONE CENT"). */
    private static function unit(int $count, string $label): string
    {
        $label = strtoupper($label);

        return $count === 1 && str_ends_with($label, 'S') ? substr($label, 0, -1) : $label;
    }

    private static function convertInteger(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        $groups = [];
        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = (int) floor($number / 1000);
        }

        $parts = [];
        foreach (array_reverse($groups, true) as $scaleIndex => $group) {
            if ($group === 0) {
                continue;
            }
            $groupWords = self::convertBelowThousand($group);
            $scale = self::$scales[$scaleIndex] ?? '';
            $parts[] = trim($groupWords . ' ' . $scale);
        }

        return implode(' ', $parts);
    }

    private static function convertBelowThousand(int $number): string
    {
        $parts = [];

        if ($number >= 100) {
            $parts[] = self::$ones[(int) floor($number / 100)] . ' HUNDRED';
            $number %= 100;
        }

        if ($number >= 20) {
            $tensWord = self::$tens[(int) floor($number / 10)];
            $unit = $number % 10;
            $parts[] = $unit > 0 ? $tensWord . '-' . self::$ones[$unit] : $tensWord;
        } elseif ($number > 0) {
            $parts[] = self::$ones[$number];
        }

        return implode(' ', $parts);
    }
}
