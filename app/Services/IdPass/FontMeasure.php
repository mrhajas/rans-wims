<?php

namespace App\Services\IdPass;

use FontLib\Font;

/**
 * How wide a text is in a card font (in em), read from the font file itself with the library DomPDF already uses, so the size chosen for a name is the size the PDF prints
 * (no GD or FreeType needed on the server). Kerning is ignored: the result is a hair too wide, which is the safe side.
 */
class FontMeasure
{
    public const FILES = ['gluten' => 'Gebuk-Regular.ttf', 'gluten-b' => 'Gebuk-Regular.ttf', 'saira' => 'PillGothic-600.ttf', 'saira-b' => 'PillGothic-600.ttf', 'saira-bb' => 'PillGothic-600.ttf'];

    private static array $maps = [];

    public static function path(string $font): string
    {
        return resource_path('idpass/fonts/' . (self::FILES[$font] ?? self::FILES['saira']));
    }

    private static function load(string $font): array
    {
        if (isset(self::$maps[$font])) {
            return self::$maps[$font];
        }
        $f = Font::load(self::path($font));
        $f->parse();
        $upm = (float) ($f->getData('head', 'unitsPerEm') ?: 1000);
        $cmap = $f->getUnicodeCharMap();
        $hmtx = $f->getData('hmtx');
        $default = isset($hmtx[0]) ? (float) $hmtx[0][0] : $upm * 0.5;
        $widths = [];
        foreach ($cmap as $code => $gid) {
            $widths[$code] = isset($hmtx[$gid]) ? (float) $hmtx[$gid][0] : $default;
        }

        return self::$maps[$font] = ['upm' => $upm, 'w' => $widths, 'default' => $default];
    }

    /** Width of $text in em. */
    public static function em(string $text, string $font): float
    {
        $m = self::load($font);
        $sum = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $sum += $m['w'][mb_ord($ch)] ?? $m['default'];
        }

        return $sum / $m['upm'];
    }

    /** Width in mm of $text set at $pt points. */
    public static function mm(string $text, string $font, float $pt): float
    {
        return self::em($text, $font) * $pt * 25.4 / 72;
    }
}
