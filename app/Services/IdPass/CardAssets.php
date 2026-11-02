<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use Illuminate\Support\Facades\Storage;

/**
 * The pictures of a card: the supplied artwork (resources/idpass/img), the icon and arrow in the colour of the category, the rotated logo, the QR code.
 * Everything made here is kept in storage/app/idpass/cache so a card is not redrawn every time it is shown or printed.
 */
class CardAssets
{
    public static function dir(string $sub = ''): string
    {
        $d = storage_path('app/idpass' . ($sub !== '' ? '/' . $sub : ''));
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    public static function art(string $name): string
    {
        return resource_path('idpass/img/' . $name);
    }

    public static function hex(?string $c, string $fallback = '#1e4467'): string
    {
        $c = strtolower(trim((string) $c));

        return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : $fallback;
    }

    /** A white mask (icon, arrow) turned into a picture of one colour with the same shape. Returns the file path. */
    public static function tinted(string $maskFile, string $hex, ?int $size = null): string
    {
        $hex = self::hex($hex);
        $out = self::dir('cache') . '/t-' . md5($maskFile . $hex . $size) . '.png';
        if (is_file($out)) {
            return $out;
        }
        $src = imagecreatefrompng(self::art($maskFile));
        if ($size && imagesx($src) > $size) {
            // a smaller picture is much faster for the PDF engine to place: icons only need as many pixels as their size on the card at about 800 pixels per inch
            $small = imagecreatetruecolor($size, (int) round(imagesy($src) * $size / imagesx($src)));
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));
            imagecopyresampled($small, $src, 0, 0, 0, 0, imagesx($small), imagesy($small), imagesx($src), imagesy($src));
            imagedestroy($src);
            $src = $small;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = (imagecolorat($src, $x, $y) >> 24) & 0x7F;
                imagesetpixel($dst, $x, $y, ($a << 24) | ($r << 16) | ($g << 8) | $b);
            }
        }
        imagepng($dst, $out, 6);
        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }

    /** The RANS logo turned a quarter to read upwards, as on the Staff ID. A vector picture (traced from the supplied logo), so it is sharp at any print size. */
    public static function logoUp(): string
    {
        return self::art('logo-up.svg');
    }

    /** The RANS logo as a vector picture: it never goes soft, whatever size or resolution the card is printed at. */
    public static function logo(): string
    {
        return self::art('logo.svg');
    }

    /** A QR code of two colours (navy on white), drawn with whole squares of enough pixels to give about 830 pixels across the 24.9 mm it is printed at (over 800 per inch). Saved as a plain RGB picture: the PDF engine places that about eight times faster than a palette picture. */
    public static function qrPng(string $text): string
    {
        $matrix = \BaconQrCode\Encoder\Encoder::encode($text, \BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();
        $n = $matrix->getWidth();
        $mod = (int) ceil(830 / ($n + 2));
        $px = ($n + 2) * $mod;
        $img = imagecreatetruecolor($px, $px);
        $white = imagecolorallocate($img, 255, 255, 255);
        $navy = imagecolorallocate($img, 0x1e, 0x44, 0x67);
        imagefilledrectangle($img, 0, 0, $px, $px, $white);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    imagefilledrectangle($img, ($x + 1) * $mod, ($y + 1) * $mod, ($x + 2) * $mod - 1, ($y + 2) * $mod - 1, $navy);
                }
            }
        }
        ob_start();
        imagepng($img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /** The QR code of a card: its verification address, navy on white, drawn large so it stays sharp when printed. */
    public static function qr(IdPassCard $card): string
    {
        $out = self::dir('cache') . '/qr-' . substr(hash('sha256', $card->token . '|' . $card->verifyUrl()), 0, 24) . '.png';
        if (! is_file($out)) {
            file_put_contents($out, self::qrPng($card->verifyUrl()));
        }

        return $out;
    }

    /** A QR code for text that is not a card yet (the preview in the issue form). */
    public static function qrFor(string $text): string
    {
        $out = self::dir('cache') . '/qrt-' . substr(sha1($text), 0, 24) . '.png';
        if (! is_file($out)) {
            file_put_contents($out, self::qrPng($text));
        }

        return $out;
    }

    /** The cropped photograph of a card (private disk), or null. */
    public static function photo(IdPassCard $card): ?string
    {
        if (! $card->photo_path) {
            return null;
        }
        $p = Storage::disk('local')->path($card->photo_path);

        return is_file($p) ? $p : null;
    }

    /** The stamp laid over the photo of a card that is not valid: label and colour per status. */
    public const STAMPS = ['Suspended' => ['SUSPENDED', '#d92d20'], 'Expired' => ['EXPIRED', '#e8710a'], 'Revoked' => ['REVOKED', '#7c2d92']];

    /** A transparent PNG of a tilted rubber stamp (double frame with notched corners, bold letters) in the colour of the status. Drawn once and kept in the cache. Null for a status that has no stamp. */
    public static function stamp(?string $status): ?string
    {
        if (! isset(self::STAMPS[(string) $status])) {
            return null;
        }
        [$label, $hex] = self::STAMPS[$status];
        $out = self::dir('cache') . '/stamp-v2-' . strtolower($status) . '.png';
        if (is_file($out)) {
            return $out;
        }
        $k = 2; // drawn twice as large, then reduced, so the edges are smooth
        $W = 760 * $k;
        $H = 420 * $k;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $ink = imagecolorallocatealpha($im, $r, $g, $b, 8);
        $clear = imagecolorallocatealpha($im, 255, 255, 255, 127);
        // the frame: a thick rounded rectangle with a thin one inside it
        $rect = function (int $x1, int $y1, int $x2, int $y2, int $rad, int $t) use ($im, $ink, $clear) {
            self::roundRect($im, $x1, $y1, $x2, $y2, $rad, $ink);
            imagealphablending($im, false);
            self::roundRect($im, $x1 + $t, $y1 + $t, $x2 - $t, $y2 - $t, max(2, $rad - $t), $clear);
            imagealphablending($im, true);
        };
        $rect(20 * $k, 20 * $k, $W - 20 * $k, $H - 20 * $k, 46 * $k, 16 * $k);
        $rect(46 * $k, 46 * $k, $W - 46 * $k, $H - 46 * $k, 26 * $k, 5 * $k);
        // the letters, as large as the frame lets them be
        $font = self::font('PillGothic-600.ttf');
        $maxW = $W - 130 * $k;
        $size = 150 * $k;
        do {
            $box = imagettfbbox($size, 0, $font, $label);
            $tw = $box[2] - $box[0];
            $size -= 4;
        } while ($tw > $maxW && $size > 20);
        $box = imagettfbbox($size, 0, $font, $label);
        $x = (int) (($W - ($box[2] - $box[0])) / 2 - $box[0]);
        $y = (int) (($H - ($box[1] - $box[7])) / 2 - $box[7]);
        // the font is a light one: drawn several times a hair apart it takes the weight of a stamp
        foreach ([[0, 0], [-3, 0], [3, 0], [0, -3], [0, 3], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
            imagettftext($im, $size, 0, $x + $dx * $k, $y + $dy * $k, $ink, $font, $label);
        }
        // tilted a little, as a real stamp is, and reduced
        $rot = imagerotate($im, 16, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagedestroy($im);
        imagealphablending($rot, false);
        imagesavealpha($rot, true);
        $rw = imagesx($rot) / $k;
        $rh = imagesy($rot) / $k;
        $dst = imagecreatetruecolor((int) $rw, (int) $rh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $rot, 0, 0, 0, 0, (int) $rw, (int) $rh, imagesx($rot), imagesy($rot));
        imagepng($dst, $out, 6);
        imagedestroy($rot);
        imagedestroy($dst);

        return $out;
    }

    private static function roundRect($im, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col);
        }
    }

    public static function font(string $file): string
    {
        return resource_path('idpass/fonts/' . $file);
    }
}
