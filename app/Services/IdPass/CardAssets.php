<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use App\Support\PrintQrCode;
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

    /** The RANS logo turned a quarter to read upwards, as on the Staff ID. */
    public static function logoUp(): string
    {
        $out = self::dir('cache') . '/logo-up.png';
        if (! is_file($out)) {
            $src = imagecreatefrompng(public_path('images/logo.png'));
            $rot = imagerotate($src, 90, imagecolorallocatealpha($src, 0, 0, 0, 127));
            imagesavealpha($rot, true);
            imagepng($rot, $out, 6);
        }

        return $out;
    }

    public static function logo(): string
    {
        return public_path('images/logo.png');
    }

    /** The QR code of a card: its verification address, navy on white, drawn large (each square is 16 pixels) so it stays sharp when printed. */
    public static function qr(IdPassCard $card): string
    {
        $out = self::dir('cache') . '/qr-' . substr(hash('sha256', $card->token . '|' . $card->verifyUrl()), 0, 24) . '.png';
        if (! is_file($out)) {
            file_put_contents($out, PrintQrCode::png($card->verifyUrl(), 16, 1, '#1e4467', 'M'));
        }

        return $out;
    }

    /** A QR code for text that is not a card yet (the preview in the issue form). */
    public static function qrFor(string $text): string
    {
        $out = self::dir('cache') . '/qrt-' . substr(sha1($text), 0, 24) . '.png';
        if (! is_file($out)) {
            file_put_contents($out, PrintQrCode::png($text, 16, 1, '#1e4467', 'M'));
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

    public static function font(string $file): string
    {
        return resource_path('idpass/fonts/' . $file);
    }
}
