<?php

namespace App\Services\IdPass;

use App\Models\Staff;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photographs of the cards. The original is kept as it came (private disk), and one card picture is made from it: cut to the 4:5 frame of the card (never stretched), at most
 * 1000 x 1250 pixels (31 mm at 800 pixels per inch is 976), saved once as a JPEG so it is not converted again at every print. A picture too small for the frame is accepted with a warning.
 */
class PhotoService
{
    public const MIN_WIDTH = 600;        // pixels across the frame below which a print looks soft

    public const MAX_KB = 10240;

    public const OUT_W = 1000;

    public const OUT_H = 1250;

    /**
     * $cutout is the transparent picture of the person that the browser made from the same frame, $bg the colour the category wants behind it:
     * with both, the card picture is the cut-out on that colour; without a cut-out, a photograph that already stands on the colour is kept as it is.
     *
     * @return array{path:string,original:?string,cutout:?string,bg:?string,width:int,height:int,low:bool}
     */
    public static function store(UploadedFile $file, ?UploadedFile $original = null, ?UploadedFile $cutout = null, ?string $bg = null): array
    {
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['photo' => __('The photograph is too large. Keep it under :mb MB.', ['mb' => self::MAX_KB / 1024])]);
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['photo' => __('The photograph must be a JPG, PNG or WEBP picture.')]);
        }
        $dir = 'idpass/photos/' . now()->format('Y/m') . '/' . Str::random(24);
        $bytes = file_get_contents($file->getRealPath());
        $card = self::cardImage($bytes);
        $jpeg = $card['jpeg'];
        $cutPath = null;
        $madeBg = null;
        if (PhotoBackground::valid($bg)) {
            $bg = strtolower($bg);
            $cutBytes = $cutout ? file_get_contents($cutout->getRealPath()) : null;
            $laid = $cutBytes && @getimagesizefromstring($cutBytes) ? PhotoBackground::composite($cutBytes, $bg) : null;
            if ($laid) {
                $jpeg = $laid;
                $cutPath = $dir . '/cutout.png';
                Storage::disk('local')->put($cutPath, $cutBytes);
                $madeBg = $bg;
            } elseif (PhotoBackground::matches($jpeg, $bg)) {
                $madeBg = $bg;       // already stands on the colour: left as it is
            }
        }
        Storage::disk('local')->put($dir . '/card.jpg', $jpeg);
        $originalPath = null;
        $src = $original ?: $file;
        $ext = ['image/png' => 'png', 'image/webp' => 'webp'][$src->getMimeType()] ?? 'jpg';
        $originalPath = $dir . '/original.' . $ext;
        Storage::disk('local')->put($originalPath, file_get_contents($src->getRealPath()));

        return ['path' => $dir . '/card.jpg', 'original' => $originalPath, 'cutout' => $cutPath, 'bg' => $madeBg, 'width' => $card['src_w'], 'height' => $card['src_h'], 'low' => $card['src_w'] < self::MIN_WIDTH];
    }

    /** 'male' or 'female' from whatever the record says (Male, Female, M, F, mwanamke...). Unknown is male. */
    public static function gender(?string $raw): string
    {
        return preg_match('/^\s*(f|female|woman|girl|mwanamke|kike)\b/i', (string) $raw) ? 'female' : 'male';
    }

    /** The avatar on the colour, as the card picture: a dark blue silhouette (male or female) standing on $bg, in the 4:5 frame. Returns the JPEG and the transparent cut-out (so a later change of the category colour is done on the server). */
    private static function avatarImages(string $gender, ?string $bg): array
    {
        $gender = $gender === 'female' ? 'female' : 'male';
        $hex = PhotoBackground::valid($bg) ? strtolower($bg) : '#e6edf6';
        $src = @imagecreatefrompng(public_path('images/avatars/' . $gender . '.png'));
        if (! $src) {
            throw ValidationException::withMessages(['photo' => __('The avatar picture is missing.')]);
        }
        $w = self::OUT_W;
        $h = self::OUT_H;
        $aw = (int) round($w * 0.94);
        $ah = (int) round(imagesy($src) * $aw / imagesx($src));
        $ax = (int) round(($w - $aw) / 2);
        $ay = $h - $ah;                                     // the shoulders reach the foot of the frame
        $cut = imagecreatetruecolor($w, $h);
        imagealphablending($cut, false);
        imagesavealpha($cut, true);
        imagefill($cut, 0, 0, imagecolorallocatealpha($cut, 0, 0, 0, 127));
        imagecopyresampled($cut, $src, $ax, $ay, 0, 0, $aw, $ah, imagesx($src), imagesy($src));
        ob_start();
        imagepng($cut, null, 6);
        $png = ob_get_clean();
        imagedestroy($src);
        imagedestroy($cut);

        return ['jpeg' => PhotoBackground::composite($png, $hex), 'png' => $png, 'bg' => $hex];
    }

    /** An avatar as the photograph of a card (saved like any photograph, with its cut-out). */
    public static function avatar(?string $gender, ?string $bg = null): array
    {
        $g = self::gender($gender);
        $img = self::avatarImages($g, $bg);
        $dir = 'idpass/photos/' . now()->format('Y/m') . '/' . Str::random(24);
        Storage::disk('local')->put($dir . '/card.jpg', $img['jpeg']);
        Storage::disk('local')->put($dir . '/cutout.png', $img['png']);

        return ['path' => $dir . '/card.jpg', 'original' => null, 'cutout' => $dir . '/cutout.png', 'bg' => $img['bg'], 'width' => self::OUT_W, 'height' => self::OUT_H, 'low' => false, 'avatar' => $g];
    }

    /** The avatar on the colour for the preview of the issue form (kept in the cache, nothing is saved on a card). */
    public static function avatarPreview(?string $gender, ?string $bg = null): string
    {
        $g = self::gender($gender);
        $hex = PhotoBackground::valid($bg) ? strtolower($bg) : '#e6edf6';
        $out = CardAssets::dir('tmp') . '/avatar-' . $g . '-' . ltrim($hex, '#') . '.jpg';
        if (! is_file($out)) {
            file_put_contents($out, self::avatarImages($g, $hex)['jpeg']);
        }

        return $out;
    }

    /** The picture of a staff member in the card frame (their profile photograph is copied, so the card does not change if the profile photo does). */
    public static function fromStaff(Staff $s, ?string $bg = null): ?array
    {
        if (! $s->photo || ! Storage::disk('assets')->exists($s->photo)) {
            return null;
        }
        $bytes = Storage::disk('assets')->get($s->photo);
        $card = self::cardImage($bytes);
        $dir = 'idpass/photos/' . now()->format('Y/m') . '/' . Str::random(24);
        Storage::disk('local')->put($dir . '/card.jpg', $card['jpeg']);
        $madeBg = PhotoBackground::valid($bg) && PhotoBackground::matches($card['jpeg'], $bg) ? strtolower($bg) : null;
        $ext = strtolower(pathinfo($s->photo, PATHINFO_EXTENSION)) ?: 'jpg';
        Storage::disk('local')->put($dir . '/original.' . $ext, $bytes);

        return ['path' => $dir . '/card.jpg', 'original' => $dir . '/original.' . $ext, 'cutout' => null, 'bg' => $madeBg, 'width' => $card['src_w'], 'height' => $card['src_h'], 'low' => $card['src_w'] < self::MIN_WIDTH];
    }

    /** The staff photograph in the card frame, kept in the cache for the preview of the issue form (nothing is saved on the staff member or a card). */
    public static function staffPreview(Staff $s): ?string
    {
        if (! $s->photo || ! Storage::disk('assets')->exists($s->photo)) {
            return null;
        }
        $out = CardAssets::dir('tmp') . '/staff-' . $s->id . '-' . md5($s->photo) . '.jpg';
        if (! is_file($out)) {
            file_put_contents($out, self::cardImage(Storage::disk('assets')->get($s->photo))['jpeg']);
        }

        return $out;
    }

    /** A cropped picture sent by the form as a data address, made into a short-lived file for the preview. */
    public static function previewFile(string $dataUri): ?string
    {
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,#', $dataUri, $m)) {
            return null;
        }
        $bytes = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        if (! $bytes || strlen($bytes) > 4 * 1024 * 1024 || ! @getimagesizefromstring($bytes)) {
            return null;
        }
        $out = CardAssets::dir('tmp') . '/pv-' . md5($bytes) . '.jpg';
        if (! is_file($out)) {
            file_put_contents($out, self::cardImage($bytes)['jpeg']);
        }

        return $out;
    }

    /** Cuts the picture to 4:5 from the centre and scales it down (never up) to the card size. */
    private static function cardImage(string $bytes): array
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw ValidationException::withMessages(['photo' => __('That picture could not be read.')]);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        // photos from phones carry a turn in their EXIF data
        if (function_exists('exif_read_data')) {
            $tmp = tempnam(sys_get_temp_dir(), 'idp');
            file_put_contents($tmp, $bytes);
            $ex = @exif_read_data($tmp);
            @unlink($tmp);
            $o = (int) ($ex['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($angle) {
                $src = imagerotate($src, $angle, 0);
                $w = imagesx($src);
                $h = imagesy($src);
            }
        }
        $ratio = self::OUT_W / self::OUT_H;
        if ($w / $h > $ratio) {
            $cw = (int) round($h * $ratio);
            $ch = $h;
            $sx = (int) round(($w - $cw) / 2);
            $sy = 0;
        } else {
            $cw = $w;
            $ch = (int) round($w / $ratio);
            $sx = 0;
            $sy = (int) round(($h - $ch) / 2);
        }
        $ow = min(self::OUT_W, $cw);
        $oh = (int) round($ow / $ratio);
        $dst = imagecreatetruecolor($ow, $oh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 175, 179, 182));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $ow, $oh, $cw, $ch);
        ob_start();
        imagejpeg($dst, null, 92);
        $jpeg = ob_get_clean();

        return ['jpeg' => $jpeg, 'src_w' => $cw, 'src_h' => $ch];
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
