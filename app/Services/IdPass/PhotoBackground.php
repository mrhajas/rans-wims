<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use Illuminate\Support\Facades\Storage;

/**
 * The background behind the photograph of an ID. The cut-out of the person (transparent PNG, made in the browser) is laid on the colour the category asks for;
 * a photograph that already stands on that colour is left alone. Both steps are plain GD, so nothing here needs a browser.
 */
class PhotoBackground
{
    /** How far (in RGB, 0 to 441) a pixel may be from the colour and still count as that colour. */
    private const TOLERANCE = 42;

    /** The share of the sampled border that must be that colour for the photograph to count as "already on it". */
    private const SHARE = 0.88;

    public static function valid(?string $hex): bool
    {
        return is_string($hex) && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
    }

    /** @return int[] */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** Whether the border of the picture (top, left and right: the bottom is shoulders) is already the colour $hex. */
    public static function matches(string $bytes, string $hex): bool
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return false;
        }
        $w = 64;
        $h = 80;
        $sm = imagecreatetruecolor($w, $h);
        imagecopyresampled($sm, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        [$r0, $g0, $b0] = self::rgb($hex);
        $n = 0;
        $hit = 0;
        for ($y = 0; $y < (int) ($h * 0.72); $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($y >= 4 && $x >= 4 && $x < $w - 4) {
                    continue;      // only the border strip: 4 rows on top, 4 columns on each side
                }
                $c = imagecolorat($sm, $x, $y);
                $d = sqrt((($c >> 16 & 255) - $r0) ** 2 + (($c >> 8 & 255) - $g0) ** 2 + (($c & 255) - $b0) ** 2);
                $n++;
                $hit += $d <= self::TOLERANCE ? 1 : 0;
            }
        }

        return $n > 0 && $hit / $n >= self::SHARE;
    }

    /** The cut-out laid on the colour, as a JPEG. */
    public static function composite(string $pngBytes, string $hex): ?string
    {
        $cut = @imagecreatefromstring($pngBytes);
        if (! $cut) {
            return null;
        }
        $w = imagesx($cut);
        $h = imagesy($cut);
        $dst = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = self::rgb($hex);
        imagefill($dst, 0, 0, imagecolorallocate($dst, $r, $g, $b));
        imagealphablending($dst, true);
        imagecopy($dst, $cut, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($dst, null, 92);

        return ob_get_clean();
    }

    /** Makes the photograph of an issued card stand on $hex. 'recoloured' (from its cut-out), 'kept' (already on the colour), or 'needs_browser' (no cut-out yet). */
    public static function apply(IdPassCard $card, string $hex): string
    {
        $disk = Storage::disk('local');
        if (! $card->photo_path || ! $disk->exists($card->photo_path)) {
            return 'no_photo';
        }
        if (strtolower((string) $card->photo_bg) === strtolower($hex)) {
            return 'kept';
        }
        if ($card->photo_cutout && $disk->exists($card->photo_cutout) && ($jpeg = self::composite($disk->get($card->photo_cutout), $hex))) {
            $dir = dirname($card->photo_path);
            $new = $dir . '/card-' . substr(md5($hex . microtime()), 0, 8) . '.jpg';
            $disk->put($new, $jpeg);
            $card->forceFill(['photo_path' => $new, 'photo_bg' => strtolower($hex)])->save();

            return 'recoloured';
        }
        if (self::matches($disk->get($card->photo_path), $hex)) {
            $card->forceFill(['photo_bg' => strtolower($hex)])->save();

            return 'kept';
        }

        return 'needs_browser';
    }
}
