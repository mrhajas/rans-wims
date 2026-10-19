<?php

namespace App\Support;

/**
 * Tanzanian mobile numbers. Whatever way a number is typed (0674020223, 674020223, 255674020223, +255 674 020 223, 00255674020223,
 * +2550674020223) it is stored as +255674020223: a plus, 255, then nine digits starting with 6 or 7. Anything else is not a mobile
 * number WIMS can send an SMS or WhatsApp message to.
 */
class TzPhone
{
    /** The canonical +255XXXXXXXXX form, or null when the value is not a valid Tanzanian mobile number. */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '255')) {
            $rest = substr($digits, 3);
            if (strlen($rest) === 10 && $rest[0] === '0') {
                $rest = substr($rest, 1); // +255 0674 020 223: the local leading zero left in by mistake
            }
        } elseif (str_starts_with($digits, '0')) {
            $rest = substr($digits, 1);
        } else {
            $rest = $digits;
        }

        return preg_match('/^[67]\d{8}$/', $rest) ? '+255' . $rest : null;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    public static function message(): string
    {
        return __('Enter a valid Tanzanian mobile number, for example 0674020223 or +255674020223.');
    }
}
