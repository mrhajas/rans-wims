<?php

namespace App\Support;

/** A picture as a small inline (data:) image, so it shows in the browser print view and in PDFs alike without a file path or a login. */
class PhotoData
{
    public static function dataUri(?string $absolutePath, int $max = 360): ?string
    {
        if (! $absolutePath || ! is_file($absolutePath)) {
            return null;
        }
        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return null;
        }
        if (! function_exists('imagecreatefromstring')) {
            return strlen($raw) < 400000 ? 'data:image/jpeg;base64,'.base64_encode($raw) : null;
        }
        $img = @imagecreatefromstring($raw);
        if (! $img) {
            return null; // for example an SVG: not drawn here
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, 82);
        $jpg = (string) ob_get_clean();

        return 'data:image/jpeg;base64,'.base64_encode($jpg);
    }
}
