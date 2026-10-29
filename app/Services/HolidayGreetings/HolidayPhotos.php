<?php

namespace App\Services\HolidayGreetings;

use App\Models\HolidayPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The photo behind the hero of a holiday's email. Every template has a WIMS photo (a file under resources/holiday/photos, credits next to it); a Super Admin can upload their own
 * photo for a template, or switch the photo off. The email shows the photo in black and white at 15% opacity, filling the whole section: that look is made once per template
 * (the photo, in grey, laid at 15% over the colour of the hero) and kept as a small JPEG in public/images/holiday/bg, so a mail app only has to show one ordinary picture.
 */
class HolidayPhotos
{
    public const OPACITY = 15;

    private const W = 1280;

    private const H = 1000;

    private const VERSION = 'v2';

    public static function dir(): string
    {
        $d = public_path('images/holiday/bg');
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    public static function defaultPath(string $theme): ?string
    {
        $p = resource_path('holiday/photos/' . basename($theme) . '.jpg');

        return is_file($p) ? $p : null;
    }

    public static function row(int $accountId, string $theme): ?HolidayPhoto
    {
        return HolidayPhoto::withoutGlobalScope('account')->where('account_id', $accountId)->where('theme', $theme)->first();
    }

    /** @return array{kind:string,path:?string} kind: custom | default | none */
    public static function source(int $accountId, string $theme): array
    {
        $row = self::row($accountId, $theme);
        if ($row && $row->disabled) {
            return ['kind' => 'none', 'path' => null];
        }
        if ($row && $row->path && is_file(storage_path('app/holiday-photos/' . $row->path))) {
            return ['kind' => 'custom', 'path' => storage_path('app/holiday-photos/' . $row->path)];
        }
        $d = self::defaultPath($theme);

        return $d ? ['kind' => 'default', 'path' => $d] : ['kind' => 'none', 'path' => null];
    }

    /**
     * The background of a template's email: the photo in grey laid at 15% over the colour of the hero, as a picture of its own.
     *
     * @return array{file:string,url:string}|null null when the template has no photo
     */
    public static function background(int $accountId, string $theme, string $primary): ?array
    {
        $src = self::source($accountId, $theme);
        if (! $src['path']) {
            return null;
        }
        $hash = substr(sha1($src['path'] . '|' . filemtime($src['path']) . '|' . $primary . '|' . self::OPACITY . '|' . self::VERSION), 0, 10);
        $name = $accountId . '-' . $theme . '-' . $hash . '.jpg';
        $file = self::dir() . '/' . $name;
        if (! is_file($file)) {
            $img = self::load($src['path']);
            if (! $img) {
                return null;
            }
            $canvas = imagecreatetruecolor(self::W, self::H);
            [$r, $g, $b] = self::rgb($primary);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
            $cover = self::cover($img, self::W, self::H);
            imagefilter($cover, IMG_FILTER_GRAYSCALE);
            imagecopymerge($canvas, $cover, 0, 0, 0, 0, self::W, self::H, self::OPACITY);
            $tmp = $file . '.tmp' . getmypid();
            imagejpeg($canvas, $tmp, 80);
            @rename($tmp, $file);
            foreach (glob(self::dir() . '/' . $accountId . '-' . $theme . '-*.jpg') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        }

        return ['file' => $file, 'url' => HolidaySettings::assetBase() . '/images/holiday/bg/' . $name];
    }

    /** A small black and white picture of the photo at full strength, for the pages of WIMS (so the Super Admin can see what is behind the email). Relative address, or null. */
    public static function thumb(int $accountId, string $theme): ?string
    {
        $src = self::source($accountId, $theme);
        if (! $src['path']) {
            return null;
        }
        $name = 'thumb-' . $accountId . '-' . $theme . '-' . substr(sha1($src['path'] . '|' . filemtime($src['path']) . '|' . self::VERSION), 0, 10) . '.jpg';
        $file = self::dir() . '/' . $name;
        if (! is_file($file)) {
            $img = self::load($src['path']);
            if (! $img) {
                return null;
            }
            $t = self::cover($img, 480, 320);
            imagefilter($t, IMG_FILTER_GRAYSCALE);
            imagejpeg($t, $file, 78);
            foreach (glob(self::dir() . '/thumb-' . $accountId . '-' . $theme . '-*.jpg') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        }

        return '/images/holiday/bg/' . $name;
    }

    /** A Super Admin's own photo for a template. Checked as a real picture, scaled down, saved as a JPEG. */
    public static function store(int $accountId, string $theme, UploadedFile $file, ?int $userId): HolidayPhoto
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 400 || $info[1] < 250) {
            throw ValidationException::withMessages(['photo' => __('Choose a JPG, PNG or WEBP picture at least 400 pixels wide.')]);
        }
        $img = self::load($file->getRealPath());
        if (! $img) {
            throw ValidationException::withMessages(['photo' => __('That picture could not be read.')]);
        }
        if (imagesx($img) > 1600) {
            $img = imagescale($img, 1600) ?: $img;
        }
        $rel = $accountId . '/' . $theme . '-' . time() . '.jpg';
        $abs = storage_path('app/holiday-photos/' . $rel);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        imagejpeg($img, $abs, 85);
        $row = self::row($accountId, $theme);
        if ($row && $row->path) {
            @unlink(storage_path('app/holiday-photos/' . $row->path));
        }
        $data = ['account_id' => $accountId, 'theme' => $theme, 'path' => $rel, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'disabled' => false, 'uploaded_by_user_id' => $userId];

        return $row ? tap($row)->update($data) : HolidayPhoto::withoutGlobalScope('account')->create($data);
    }

    /** Back to the WIMS photo of the template. */
    public static function reset(int $accountId, string $theme): void
    {
        $row = self::row($accountId, $theme);
        if ($row) {
            if ($row->path) {
                @unlink(storage_path('app/holiday-photos/' . $row->path));
            }
            $row->forceDelete();
        }
    }

    /** No photo for this template. */
    public static function disable(int $accountId, string $theme, ?int $userId): void
    {
        $row = self::row($accountId, $theme);
        $data = ['account_id' => $accountId, 'theme' => $theme, 'disabled' => true, 'uploaded_by_user_id' => $userId];
        $row ? $row->update($data) : HolidayPhoto::withoutGlobalScope('account')->create($data);
    }

    private static function load(string $path)
    {
        $info = @getimagesize($path);
        if (! $info) {
            return null;
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        return $img ?: null;
    }

    /** The picture scaled to fill $w x $h and cropped from the middle. */
    private static function cover($img, int $w, int $h)
    {
        $sw = imagesx($img);
        $sh = imagesy($img);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $ch = (int) round($h / $scale);
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $img, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);

        return $out;
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
}
