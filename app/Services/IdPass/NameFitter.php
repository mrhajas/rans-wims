<?php

namespace App\Services\IdPass;

/**
 * Makes a name (or any line) fit its box without running over it. For a person's name the order is the one the owner asked for: a little smaller first, then the
 * middle names become initials ("Habiil Jamal Salim" -> "Habiil J. Salim"), smaller again, then the first name too ("H. J. Salim"), and only at the very end the text is cut with "...".
 */
class NameFitter
{
    /** "ASHA NASSOR SALIM" is shown as "Asha Nassor Salim"; a name that already has lower case letters is left as typed. */
    public static function display(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name !== '' && $name === mb_strtoupper($name) && $name !== mb_strtolower($name)) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }
        // "Eng.Abdulmajid" -> "Eng. Abdulmajid", and a lone letter in a name is an initial: "Majid S Salim" -> "Majid S. Salim"
        $name = preg_replace('/\b(Eng|Dr|Mr|Mrs|Ms|Prof|Hon|Arch|Capt|Col)\.(?=\p{L})/iu', '$1. ', $name);
        $name = preg_replace('/(?<=\s)(\p{Lu})(?=\s|$)/u', '$1.', $name);

        return $name;
    }

    private static function initial(string $word): string
    {
        // keep hyphenated surnames whole; only plain words become an initial
        return mb_strtoupper(mb_substr($word, 0, 1)) . '.';
    }

    /** @return string[] the shapes of a name, from the full name to the shortest */
    public static function shapes(string $name): array
    {
        $parts = array_values(array_filter(explode(' ', self::display($name))));
        $shapes = [implode(' ', $parts)];
        if (count($parts) >= 3) {
            $mid = array_map(fn ($p) => (mb_strlen(rtrim($p, '.')) <= 1 || str_ends_with($p, '.')) ? $p : self::initial($p), array_slice($parts, 1, -1));
            $shapes[] = $parts[0] . ' ' . implode(' ', $mid) . ' ' . end($parts);
        }
        if (count($parts) >= 2) {
            $first = array_map(fn ($p) => str_ends_with($p, '.') ? $p : self::initial($p), array_slice($parts, 0, -1));
            $shapes[] = implode(' ', $first) . ' ' . end($parts);
        }

        return array_values(array_unique($shapes));
    }

    /**
     * @return array{text:string,pt:float,abbreviated:bool,cut:bool}
     */
    public static function fitName(string $name, string $font, float $maxMm, float $startPt, float $minPt): array
    {
        $shapes = self::shapes(FontMeasure::safe($name, $font));
        $last = count($shapes) - 1;
        foreach ($shapes as $i => $shape) {
            // a little smaller first; the shortest shape may shrink further before the text is cut
            foreach ($i === $last ? [1.0, 0.9, 0.8, 0.7] : ($i === 0 ? [1.0, 0.9] : [1.0, 0.9, 0.8]) as $k) {
                $pt = round(max($minPt, $startPt * $k), 2);
                if (FontMeasure::mm($shape, $font, $pt) <= $maxMm) {
                    return ['text' => $shape, 'pt' => $pt, 'abbreviated' => $i > 0, 'cut' => false];
                }
            }
        }

        return self::fitLine(end($shapes), $font, $maxMm, $startPt * 0.7, $minPt);
    }

    /** One line (a designation, a vehicle, a number): smaller until it fits, then cut with "...". */
    public static function fitLine(string $text, string $font, float $maxMm, float $startPt, float $minPt): array
    {
        $text = FontMeasure::safe(trim($text), $font);
        $pt = $startPt;
        while ($pt > $minPt && FontMeasure::mm($text, $font, $pt) > $maxMm) {
            $pt = round($pt - 0.25, 2);
        }
        $pt = max($pt, $minPt);
        if (FontMeasure::mm($text, $font, $pt) <= $maxMm) {
            return ['text' => $text, 'pt' => $pt, 'abbreviated' => false, 'cut' => false];
        }
        $cut = $text;
        while (mb_strlen($cut) > 1 && FontMeasure::mm($cut . '...', $font, $pt) > $maxMm) {
            $cut = rtrim(mb_substr($cut, 0, -1));
        }

        return ['text' => $cut . '...', 'pt' => $pt, 'abbreviated' => false, 'cut' => true];
    }
}
