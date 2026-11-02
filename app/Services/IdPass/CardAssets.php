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

    /**
     * The drawn person shown when a card has no photograph, in the colours of its category: the supplied blue icon (null = blue on white, as supplied), or the same drawing with
     * every colour turned to the hue of the category colour, standing on the photo background colour of the category. The shades are made lighter on a dark background and darker
     * on a light one, and a white rim separates the figure from the background, so the person is always seen clearly. $flat is the gradient-free copy for the PDF engine.
     * Returns the file path (kept in the cache).
     */
    public static function person(?string $hex = null, bool $flat = false, ?string $bg = null): string
    {
        $base = self::art($flat ? 'person-flat.svg' : 'person.svg');
        $hex = $hex === null || $hex === 'blue' ? null : self::hex($hex, '');
        $bg = $bg ? self::hex($bg, '') : '';
        if (($hex === null || $hex === '') && $bg === '') {
            return $base;
        }
        $out = self::dir('cache') . '/person-v3-' . ($flat ? 'f' : 'g') . '-' . ltrim((string) $hex, '#') . '-' . ltrim($bg, '#') . '.svg';
        if (! is_file($out)) {
            [$hue, $sat, $tl] = $hex ? self::hsl(...sscanf($hex, '#%02x%02x%02x')) : [null, 0.0, 0.0];
            // how light the background is decides which way the shades of the person move
            $lum = 1.0;
            if ($bg !== '') {
                [$br, $bgr, $bb] = sscanf($bg, '#%02x%02x%02x');
                $lin = fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
                $lum = 0.2126 * $lin($br) + 0.7152 * $lin($bgr) + 0.0722 * $lin($bb);
            }
            $svg = (string) file_get_contents($base);
            $svg = preg_replace_callback('/#([0-9a-f]{6})\b/i', function ($m) use ($hue, $sat, $tl, $lum) {
                if (strtolower($m[1]) === 'ffffff') {
                    return $m[0];
                }
                [$cr, $cg, $cb] = sscanf($m[0], '#%02x%02x%02x');
                [$h0, $s, $l] = self::hsl($cr, $cg, $cb);
                if ($lum < 0.99) {
                    $l = $lum >= 0.3 ? $l * 0.6 : $l + (1 - $l) * 0.32; // light background: deeper shades; dark background: lighter shades
                }
                // a grey category (silver) keeps a pale tint of its hue; a coloured one keeps the strength of the original shading
                // a bright category colour (the RANS orange-yellow) keeps its full strength, or it turns to a dull brown in the dark shades
                $ns = $hue === null ? $s : ($sat < 0.12 ? $sat : ($tl >= 0.4 ? min(1.0, max($s, $sat * 0.9)) : min(1.0, $s * 1.05)));
                [$nr, $ng, $nb] = self::rgb($hue ?? $h0, $ns, $tl >= 0.4 && $hue !== null ? min(0.92, $l * 1.12 + 0.04) : $l);

                return sprintf('#%02x%02x%02x', $nr, $ng, $nb);
            }, $svg);
            if ($bg !== '') {
                $svg = str_replace('<rect width="310" height="387.5" fill="#ffffff"/>', '<rect width="310" height="387.5" fill="' . $bg . '"/>', $svg);
            }
            file_put_contents($out, $svg);
        }

        return $out;
    }

    /** @return array{0: float, 1: float, 2: float} hue (0-360), saturation and lightness (0-1) */
    private static function hsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0) {
            return [0.0, 0.0, $l];
        }
        $s = $d / (1 - abs(2 * $l - 1));
        $h = match (true) {
            $max === $r => 60 * fmod((($g - $b) / $d), 6),
            $max === $g => 60 * ((($b - $r) / $d) + 2),
            default => 60 * ((($r - $g) / $d) + 4),
        };

        return [fmod($h + 360, 360), $s, $l];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x], $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    /** The key of the drawn person of a category in a URL: 'blue' for a Staff ID, else the band colour and the photo background (six hex digits each, joined by a dash). */
    public static function personKey(?string $kind, ?string $band, ?string $bg = null): string
    {
        if ($kind === 'staff' || ! $band) {
            return 'blue';
        }

        return ltrim(self::hex($band, '#1565c0'), '#') . ($bg && self::hex($bg, '') !== '' ? '-' . ltrim(self::hex($bg, ''), '#') : '');
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
        $out = self::dir('cache') . '/stamp-v5-' . strtolower($status) . '.png';
        if (is_file($out)) {
            return $out;
        }
        $k = 2; // drawn twice as large, then reduced, so the edges are smooth
        $W = 760 * $k;
        $H = 420 * $k;
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
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
        $blank = function () use ($W, $H) {
            $c = imagecreatetruecolor($W, $H);
            imagealphablending($c, false);
            imagesavealpha($c, true);
            imagefill($c, 0, 0, imagecolorallocatealpha($c, 255, 255, 255, 127));
            imagealphablending($c, true);

            return $c;
        };
        // the whole stamp in one colour (the frame: a thick rounded rectangle with a thin one inside it; then the letters)
        $draw = function ($c, int $cr, int $cg, int $cb, int $alpha) use ($W, $H, $k, $font, $size, $x, $y, $label) {
            $ink = imagecolorallocatealpha($c, $cr, $cg, $cb, $alpha);
            $clear = imagecolorallocatealpha($c, 255, 255, 255, 127);
            $rect = function (int $x1, int $y1, int $x2, int $y2, int $rad, int $t) use ($c, $ink, $clear) {
                self::roundRect($c, $x1, $y1, $x2, $y2, $rad, $ink);
                imagealphablending($c, false);
                self::roundRect($c, $x1 + $t, $y1 + $t, $x2 - $t, $y2 - $t, max(2, $rad - $t), $clear);
                imagealphablending($c, true);
            };
            $rect(20 * $k, 20 * $k, $W - 20 * $k, $H - 20 * $k, 46 * $k, 16 * $k);
            $rect(46 * $k, 46 * $k, $W - 46 * $k, $H - 46 * $k, 26 * $k, 5 * $k);
            // the font is a light one: drawn several times a hair apart it takes the weight of a stamp
            foreach ([[0, 0], [-3, 0], [3, 0], [0, -3], [0, 3], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
                imagettftext($c, $size, 0, $x + $dx * $k, $y + $dy * $k, $ink, $font, $label);
            }
        };
        $white = $blank();
        $draw($white, 255, 255, 255, 0);
        $im = $blank();
        // a white edge round everything, so the stamp reads on a photograph or colour of any kind
        $edge = 6 * $k;
        for ($a = 0; $a < 16; $a++) {
            imagecopy($im, $white, (int) round(cos($a * M_PI / 8) * $edge), (int) round(sin($a * M_PI / 8) * $edge), 0, 0, $W, $H);
        }
        imagedestroy($white);
        // and a pale plate behind the letters, like the ink of a stamp on paper
        self::roundRect($im, 46 * $k, 46 * $k, $W - 46 * $k, $H - 46 * $k, 26 * $k, imagecolorallocatealpha($im, 255, 255, 255, 45));
        $stamp = $blank();
        $draw($stamp, $r, $g, $b, 8);
        imagecopy($im, $stamp, 0, 0, 0, 0, $W, $H);
        imagedestroy($stamp);
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
