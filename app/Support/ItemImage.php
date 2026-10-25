<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Item photos are made small without looking worse: scaled down to a sensible size (never up), turned the right way round, and saved again as JPEG (82) or, when the
 * picture has a transparent background, as WebP (88) which keeps the transparency. A photo is 300-600 KB instead of 3-8 MB. A BinCard keeps a larger size, because the
 * writing on it must stay readable.
 */
class ItemImage
{
    public const PHOTO_MAX = 1600;
    public const BINCARD_MAX = 2200;

    /** Compresses the file and stores it on the assets disk under items/; returns the stored path. */
    public static function store(UploadedFile $file, string $kind = 'photo'): string
    {
        return self::storeInfo($file, $kind)[0];
    }

    /** @return array{0:string,1:bool} the stored path, and whether the picture has a transparent background (a cut-out) */
    public static function storeInfo(UploadedFile $file, string $kind = 'photo'): array
    {
        [$bytes, $ext] = self::compress((string) file_get_contents($file->getRealPath()), $kind === 'bincard' ? self::BINCARD_MAX : self::PHOTO_MAX, $file->getClientOriginalExtension());
        $path = 'items/' . Str::uuid() . '.' . $ext;
        Storage::disk('assets')->put($path, $bytes);

        // opaque pictures are always saved as JPEG, so a WebP here is a picture with a transparent background (a cut-out)
        return [$path, $ext === 'webp'];
    }

    /** @return array{0:string,1:string} the compressed bytes and the extension to give them */
    public static function compress(string $contents, int $maxSide, string $originalExt = 'jpg'): array
    {
        $info = @getimagesizefromstring($contents);
        $img = $info ? @imagecreatefromstring($contents) : false;
        if (! $img) {
            return [$contents, strtolower($originalExt) ?: 'jpg']; // not a picture GD can read (an SVG, for one): kept as it is
        }
        if (in_array($info['mime'] ?? '', ['image/png', 'image/webp'], true)) {
            imagealphablending($img, false); // keep the transparency through rotating and scaling
            imagesavealpha($img, true);
        }
        $w = imagesx($img);
        $h = imagesy($img);

        // the camera's rotation flag
        if (($info['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
            $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($rot) {
                $img = imagerotate($img, $rot, 0) ?: $img;
                $w = imagesx($img);
                $h = imagesy($img);
            }
        }

        $scale = min(1, $maxSide / max($w, $h));
        if ($scale < 1) {
            // imagecopyresampled keeps the picture sharp when it is made much smaller (and the transparency of a cut-out)
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $small = imagecreatetruecolor($nw, $nh);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));
            imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, imagesx($img), imagesy($img));
            imagedestroy($img);
            $img = $small;
        }

        $alpha = self::hasTransparency($img, $info['mime'] ?? '');
        ob_start();
        if ($alpha) {
            imagesavealpha($img, true);
            imagewebp($img, null, 88);
            $ext = 'webp';
        } else {
            $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imageinterlace($flat, true);
            imagejpeg($flat, null, 82);
            imagedestroy($flat);
            $ext = 'jpg';
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        // never make a small, already well-packed file bigger
        if ($scale >= 1 && strlen($bytes) >= strlen($contents) && in_array(strtolower($originalExt), ['jpg', 'jpeg', 'webp'], true)) {
            return [$contents, strtolower($originalExt) === 'jpeg' ? 'jpg' : strtolower($originalExt)];
        }

        return [$bytes, $ext];
    }

    private static function hasTransparency($img, string $mime): bool
    {
        if (! in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            return false;
        }
        // look at a sample grid: a cut-out has a transparent border
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(max($w, $h) / 48));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 8) {
                    return true;
                }
            }
        }

        return false;
    }
}
