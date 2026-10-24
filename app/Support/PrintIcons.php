<?php

namespace App\Support;

/** Which small icon goes in front of a contact label (Email, Mobile, Phone, Address, Website) in a printed report. */
class PrintIcons
{
    public static function forLabel(?string $label): ?string
    {
        $l = mb_strtolower(trim((string) $label));
        if ($l === '') {
            return null;
        }
        if (preg_match('/e-?mail/', $l)) {
            return 'mail';
        }
        if (preg_match('/^(mobile|phone|tel|telephone|cell|contact (no|number)|mobile (no|number)|phone (no|number))\b/', $l) || str_contains($l, 'mobile') || str_contains($l, 'phone')) {
            return 'phone';
        }
        if (preg_match('/address|location|postal|physical/', $l)) {
            return 'map-pin';
        }
        if (preg_match('/website|web site|url/', $l)) {
            return 'globe';
        }

        return null;
    }
}
